<?php

namespace App\Jobs;

use App\Enums\ActivationMode;
use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowDisposition;
use App\Models\PriceListImport;
use App\Services\PriceList\ImportActivator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizePriceListImportPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly PriceListImport $import) {}

    public function handle(ImportActivator $activator): void
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
        if (($import->profile_snapshot['activation_mode'] ?? 'manual') === ActivationMode::Automatic->value && $this->meetsThreshold($import->fresh())) {
            $activator->activate($import->fresh());
        }
    }

    private function meetsThreshold(PriceListImport $import): bool
    {
        $threshold = $import->profile_snapshot['automatic'];
        $percentage = $import->total_rows > 0 ? ($import->error_rows / $import->total_rows) * 100 : 100;

        return $import->valid_rows >= $threshold['minimum_valid_rows'] && $import->error_rows <= $threshold['maximum_error_rows'] && $percentage <= $threshold['maximum_error_percentage'];
    }
}
