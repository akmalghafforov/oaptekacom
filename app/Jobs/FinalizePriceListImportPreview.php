<?php

namespace App\Jobs;

use App\Enums\ActivationMode;
use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowDisposition;
use App\Models\PriceListImport;
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
        $freshImport->update(['summary' => array_replace($freshImport->summary ?? [], ['rule_set_checksum' => $freshImport->product_category_rule_set_checksum, 'category_distribution' => $categoryCounts, 'categorization' => $statusCounts, 'unmatched_rate' => $freshImport->total_rows ? round(((int) ($statusCounts['unmatched'] ?? 0) / $freshImport->total_rows) * 100, 2) : 0, 'ambiguity_rate' => $freshImport->total_rows ? round(((int) ($statusCounts['ambiguous'] ?? 0) / $freshImport->total_rows) * 100, 2) : 0])]);
        $candidates->extract($freshImport);
        if (($import->profile_snapshot['activation_mode'] ?? 'manual') === ActivationMode::Automatic->value && $this->meetsThreshold($import->fresh())) {
            CommitPriceListImport::dispatch($import->fresh())->onQueue(config('price-list-imports.queue'));
        }
    }

    private function meetsThreshold(PriceListImport $import): bool
    {
        $threshold = $import->profile_snapshot['automatic'];
        $percentage = $import->total_rows > 0 ? ($import->error_rows / $import->total_rows) * 100 : 100;

        return $import->valid_rows >= $threshold['minimum_valid_rows'] && $import->error_rows <= $threshold['maximum_error_rows'] && $percentage <= $threshold['maximum_error_percentage'];
    }
}
