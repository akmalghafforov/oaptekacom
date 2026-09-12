<?php

namespace App\Jobs;

use App\Enums\PriceListImportStatus;
use App\Models\PriceListImport;
use App\Services\PriceList\ValueNormalizer;
use App\Services\PriceList\WorkbookReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Throwable;

class PreparePriceListImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly PriceListImport $import) {}

    public function handle(WorkbookReader $reader, ValueNormalizer $normalizer): void
    {
        $import = $this->import->fresh();
        if ($import->status !== PriceListImportStatus::Pending) {
            return;
        }
        $info = $reader->inspect($import);
        if ($info['highest_row'] > config('price-list-imports.max_rows') || $info['highest_column'] > config('price-list-imports.max_columns')) {
            throw new RuntimeException('Файл превышает допустимый лимит строк или столбцов.');
        }
        $start = (int) $import->profile_snapshot['data_row'];
        $highestDataRow = $reader->highestDataRow($import, $info['highest_row']);
        $inventoryAt = $this->inventoryAt($import, $reader, $normalizer);
        $import->update(['status' => PriceListImportStatus::Processing, 'processing_started_at' => now(), 'inventory_at' => $inventoryAt, 'total_rows' => max(0, $highestDataRow - $start + 1), 'summary' => ['worksheets' => $info['worksheets'], 'highest_row' => $highestDataRow, 'highest_column' => $info['highest_column']]]);
        $jobs = [];
        for ($row = $start; $row <= $highestDataRow; $row += (int) config('price-list-imports.chunk_size')) {
            $jobs[] = new ProcessPriceListImportChunk($import, $row, min($row + (int) config('price-list-imports.chunk_size') - 1, $highestDataRow));
        }
        $jobs[] = new FinalizePriceListImportPreview($import);
        Bus::chain($jobs)->onQueue(config('price-list-imports.queue'))->dispatch();
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
        $this->import->update(['status' => PriceListImportStatus::Failed, 'failed_at' => now(), 'failure_message' => mb_substr($exception?->getMessage() ?? 'Неизвестная ошибка', 0, 2000)]);
    }
}
