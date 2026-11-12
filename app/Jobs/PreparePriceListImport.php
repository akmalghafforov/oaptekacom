<?php

namespace App\Jobs;

use App\Enums\PriceListImportStatus;
use App\Models\PriceListImport;
use App\Services\PriceList\ImportLayoutDetector;
use App\Services\PriceList\ValueNormalizer;
use App\Services\PriceList\WorkbookReader;
use App\Services\SupplierSourcePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use Throwable;

class PreparePriceListImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly PriceListImport $import) {}

    public int $timeout = 120;

    public int $maxExceptions = 3;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function middleware(): array
    {
        if (config('queue.connections.'.($this->connection ?? config('queue.default')).'.driver') === 'sync') {
            return [];
        }

        return [(new WithoutOverlapping('price-list-stage:'.$this->import->id))->shared()->releaseAfter(30)->expireAfter(140)];
    }

    public function handle(WorkbookReader $reader, ValueNormalizer $normalizer, ?ImportLayoutDetector $layoutDetector = null): void
    {
        $layoutDetector ??= app(ImportLayoutDetector::class);
        $import = $this->import->fresh();
        if (! in_array($import->status, [PriceListImportStatus::Pending, PriceListImportStatus::Processing], true)) {
            return;
        }
        if ($import->status === PriceListImportStatus::Pending) {
            $info = $reader->inspect($import);
            if ($info['highest_row'] > config('price-list-imports.max_rows') || $info['highest_column'] > config('price-list-imports.max_columns')) {
                throw new RuntimeException('Файл превышает допустимый лимит строк или столбцов.');
            }
            $detection = $layoutDetector->detect($import, $info);
            $import->update(['effective_layout' => $detection['layout']]);
            $import = $import->fresh();
            $start = (int) $import->effective_layout['data_row'];
            $highestDataRow = $reader->highestDataRow($import, $info['highest_row']);
            $inventoryAt = in_array($import->source_type->value, ['icorp', 'pricelist_tj'], true)
                ? $import->source_published_at : $this->inventoryAt($import, $reader, $normalizer);
            $newest = app(SupplierSourcePolicy::class)->configuration($import)?->usesNewestAvailable() ?? false;
            if ($import->source_type->value === 'email' && $import->source_metadata !== null) {
                $publication = $newest ? $this->publicationDate($import, $reader, $normalizer) : null;
                $import->update(['source_published_at' => $newest ? $publication['date'] : $inventoryAt,
                    'source_date_precision' => $newest ? $publication['precision'] : ((($import->profile_snapshot['inventory']['source'] ?? 'upload_time') === 'upload_time' || ($import->received_at && $import->received_at->equalTo($inventoryAt))) ? 'second' : 'day'),
                    'source_metadata' => array_replace($import->source_metadata, $newest ? ['publication_origin' => $publication['origin']] : [])]);
            }
            $summary = ['worksheets' => $info['worksheets'], 'highest_row' => $highestDataRow, 'highest_column' => $info['highest_column'], 'layout_detection' => ['confidence' => $detection['confidence'], 'warning' => $detection['warning']]];
            $import->update(['status' => PriceListImportStatus::Processing, 'processing_started_at' => now(), 'inventory_at' => $inventoryAt, 'total_rows' => max(0, $highestDataRow - $start + 1), 'summary' => $summary]);
        } else {
            $start = (int) $import->effective_layout['data_row'];
            $highestDataRow = (int) $import->summary['highest_row'];
        }
        $jobs = [];
        for ($row = $start; $row <= $highestDataRow; $row += (int) config('price-list-imports.chunk_size')) {
            $jobs[] = new ProcessPriceListImportChunk($import, $row, min($row + (int) config('price-list-imports.chunk_size') - 1, $highestDataRow));
        }
        $jobs[] = new FinalizePriceListImportPreview($import);
        Bus::chain($jobs)->onQueue(config('price-list-imports.queue'))->dispatch();
    }

    /** @return array{date: ?CarbonImmutable, precision: ?string, origin: ?string} */
    private function publicationDate(PriceListImport $import, WorkbookReader $reader, ValueNormalizer $normalizer): array
    {
        $configuration = $import->profile_snapshot['inventory'] ?? [];
        if (($configuration['source'] ?? null) === 'cell' && preg_match('/^([A-Z]{1,3})(\d+)$/', strtoupper((string) ($configuration['cell'] ?? '')), $matches)) {
            $value = $reader->rows($import, (int) $matches[2], (int) $matches[2])[(int) $matches[2]][$matches[1]] ?? null;
        } elseif (($configuration['source'] ?? null) === 'filename' && @preg_match((string) ($configuration['pattern'] ?? ''), $import->original_filename, $matches) === 1) {
            $value = $matches[(int) ($configuration['group'] ?? 1)] ?? null;
        } else {
            return ['date' => null, 'precision' => null, 'origin' => null];
        }

        if ($configuration['source'] === 'cell' && (is_int($value) || is_float($value)) && $value > 0) {
            $date = CarbonImmutable::instance(Date::excelToDateTimeObject((float) $value, config('price-list-imports.timezone')));

            return ['date' => $date, 'precision' => (float) $value === floor((float) $value) ? 'day' : 'second', 'origin' => $configuration['source']];
        }
        if ($value instanceof \DateTimeInterface) {
            return ['date' => CarbonImmutable::instance($value)->setTimezone(config('price-list-imports.timezone')), 'precision' => 'second', 'origin' => $configuration['source']];
        }
        foreach ($configuration['formats'] ?? $import->profile_snapshot['date_formats'] as $format) {
            if (is_string($value) && is_numeric($value)) {
                try {
                    $date = CarbonImmutable::createFromFormat('!'.$format, trim($value), config('price-list-imports.timezone'));
                    if (! $date || $date->format($format) !== trim($value)) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }
            } else {
                $date = $normalizer->date($value, [$format]);
            }
            if ($date) {
                $precision = str_contains($format, 's') ? 'second' : (str_contains($format, 'i') ? 'minute' : 'day');

                return ['date' => $date, 'precision' => $precision, 'origin' => $configuration['source']];
            }
        }

        return ['date' => null, 'precision' => null, 'origin' => $configuration['source']];
    }

    private function inventoryAt(PriceListImport $import, WorkbookReader $reader, ValueNormalizer $normalizer): mixed
    {
        $configuration = $import->profile_snapshot['inventory'] ?? ['source' => 'upload_time'];
        if (($configuration['source'] ?? 'upload_time') === 'cell' && preg_match('/^([A-Z]{1,3})(\d+)$/', strtoupper((string) ($configuration['cell'] ?? '')), $matches)) {
            $value = $reader->rows($import, (int) $matches[2], (int) $matches[2])[(int) $matches[2]][$matches[1]] ?? null;

            return $normalizer->date($value, $configuration['formats'] ?? $import->profile_snapshot['date_formats']) ?? $import->received_at ?? $import->created_at;
        }
        if (($configuration['source'] ?? null) === 'filename' && @preg_match((string) ($configuration['pattern'] ?? ''), $import->original_filename, $matches) === 1) {
            return $normalizer->date($matches[(int) ($configuration['group'] ?? 1)] ?? null, $configuration['formats'] ?? $import->profile_snapshot['date_formats']) ?? $import->received_at ?? $import->created_at;
        }

        return $import->received_at ?? $import->created_at;
    }

    public function failed(?Throwable $exception): void
    {
        $this->import->newQuery()->whereKey($this->import->id)->whereIn('status', [PriceListImportStatus::Pending, PriceListImportStatus::Processing])->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'preparation', 'failed_at' => now(), 'failure_message' => mb_substr($exception?->getMessage() ?? 'Неизвестная ошибка', 0, 2000)]);
    }
}
