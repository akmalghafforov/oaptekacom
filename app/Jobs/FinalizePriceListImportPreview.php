<?php

namespace App\Jobs;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowDisposition;
use App\Models\PriceListImport;
use App\Services\PriceList\ActivationDispatcher;
use App\Services\PriceList\CategoryCandidateExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class FinalizePriceListImportPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly PriceListImport $import) {}

    public int $timeout = 120;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

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

    public function handle(CategoryCandidateExtractor $candidates): void
    {
        $import = $this->import->fresh();
        if ($import->status !== PriceListImportStatus::Processing) {
            return;
        }
        $counts = $import->rows()->selectRaw('disposition, count(*) as aggregate')->groupBy('disposition')->pluck('aggregate', 'disposition');
        $attributes = [
            'valid_rows' => (int) ($counts[PriceListRowDisposition::Valid->value] ?? 0) + (int) ($counts[PriceListRowDisposition::Warning->value] ?? 0),
            'error_rows' => (int) ($counts[PriceListRowDisposition::Error->value] ?? 0),
            'warning_rows' => (int) ($counts[PriceListRowDisposition::Warning->value] ?? 0),
            'skipped_rows' => (int) ($counts[PriceListRowDisposition::Skipped->value] ?? 0),
        ];
        $categoryCounts = $import->rows()->selectRaw('assigned_category, count(*) as aggregate')->whereNotNull('assigned_category')->groupBy('assigned_category')->pluck('aggregate', 'assigned_category');
        $statusCounts = $import->rows()->selectRaw('categorization_status, count(*) as aggregate')->whereNotNull('categorization_status')->groupBy('categorization_status')->pluck('aggregate', 'categorization_status');
        $categoryCountDistribution = [];
        $suppressed = 0;
        foreach ($import->rows()->select(['id', 'assigned_categories', 'category_candidates'])->lazyById(100) as $row) {
            $count = count($row->assigned_categories ?? []);
            $categoryCountDistribution[$count] = ($categoryCountDistribution[$count] ?? 0) + 1;
            foreach ($row->category_candidates ?? [] as $candidate) {
                if (($candidate['decision'] ?? null) === 'suppressed') {
                    $suppressed++;
                }
            }
        }
        $attributes['summary'] = array_replace($import->summary ?? [], ['rule_set_checksum' => $import->product_category_rule_set_checksum, 'category_distribution' => $categoryCounts, 'category_count_distribution' => $categoryCountDistribution, 'categorization' => $statusCounts, 'suppressed_match_count' => $suppressed, 'uncategorized_rate' => $import->total_rows ? round(((int) ($statusCounts['uncategorized'] ?? 0) / $import->total_rows) * 100, 2) : 0, 'attention_needed_rate' => $import->total_rows ? round(((int) ($statusCounts['attention_needed'] ?? 0) / $import->total_rows) * 100, 2) : 0]);
        $candidates->extract($import);
        if ($attributes['valid_rows'] === 0) {
            $import->update(array_replace($attributes, ['status' => PriceListImportStatus::Failed, 'failure_stage' => 'parsing', 'failed_at' => now(), 'failure_message' => 'Нет корректных строк для публикации.']));

            return;
        }

        DB::transaction(function () use ($import, $attributes): void {
            $import->update(array_replace($attributes, ['status' => PriceListImportStatus::Preview, 'previewed_at' => now()]));
            app(ActivationDispatcher::class)->dispatch($import);
        });
    }

    public function failed(?\Throwable $exception): void
    {
        PriceListImport::whereKey($this->import->id)->whereIn('status', [PriceListImportStatus::Processing, PriceListImportStatus::Preview])->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'parsing', 'failed_at' => now(), 'failure_message' => 'Import finalization failed.']);
    }
}
