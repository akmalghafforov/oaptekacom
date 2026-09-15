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
use Illuminate\Queue\SerializesModels;

class FinalizePriceListImportPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly PriceListImport $import) {}

    public function handle(CategoryCandidateExtractor $candidates): void
    {
        $import = $this->import->fresh();
        $counts = $import->rows()->selectRaw('disposition, count(*) as aggregate')->groupBy('disposition')->pluck('aggregate', 'disposition');
        $attributes = [
            'status' => PriceListImportStatus::Preview, 'previewed_at' => now(),
            'valid_rows' => (int) ($counts[PriceListRowDisposition::Valid->value] ?? 0) + (int) ($counts[PriceListRowDisposition::Warning->value] ?? 0),
            'error_rows' => (int) ($counts[PriceListRowDisposition::Error->value] ?? 0),
            'warning_rows' => (int) ($counts[PriceListRowDisposition::Warning->value] ?? 0),
            'skipped_rows' => (int) ($counts[PriceListRowDisposition::Skipped->value] ?? 0),
        ];
        $import->update($attributes);
        $freshImport = $import->fresh();
        $categoryCounts = $freshImport->rows()->selectRaw('assigned_category, count(*) as aggregate')->whereNotNull('assigned_category')->groupBy('assigned_category')->pluck('aggregate', 'assigned_category');
        $statusCounts = $freshImport->rows()->selectRaw('categorization_status, count(*) as aggregate')->whereNotNull('categorization_status')->groupBy('categorization_status')->pluck('aggregate', 'categorization_status');
        $categoryCountDistribution = $freshImport->rows()->get(['assigned_categories'])->countBy(fn ($row): int => count($row->assigned_categories ?? []));
        $suppressed = $freshImport->rows()->get(['category_candidates'])->sum(fn ($row): int => collect($row->category_candidates ?? [])->where('decision', 'suppressed')->count());
        $freshImport->update(['summary' => array_replace($freshImport->summary ?? [], ['rule_set_checksum' => $freshImport->product_category_rule_set_checksum, 'category_distribution' => $categoryCounts, 'category_count_distribution' => $categoryCountDistribution, 'categorization' => $statusCounts, 'suppressed_match_count' => $suppressed, 'uncategorized_rate' => $freshImport->total_rows ? round(((int) ($statusCounts['uncategorized'] ?? 0) / $freshImport->total_rows) * 100, 2) : 0, 'attention_needed_rate' => $freshImport->total_rows ? round(((int) ($statusCounts['attention_needed'] ?? 0) / $freshImport->total_rows) * 100, 2) : 0])]);
        $candidates->extract($freshImport);
        if ($freshImport->valid_rows === 0) {
            $freshImport->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'parsing', 'failed_at' => now(), 'failure_message' => 'Нет корректных строк для публикации.']);

            return;
        }

        app(ActivationDispatcher::class)->dispatch($freshImport);
    }
}
