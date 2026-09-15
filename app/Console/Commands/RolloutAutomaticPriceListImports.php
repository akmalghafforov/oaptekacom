<?php

namespace App\Console\Commands;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowDisposition;
use App\Jobs\PreparePriceListImport;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Services\PriceList\ActivationDispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('price-list-imports:rollout-automatic')]
#[Description('Release legacy approval-gated imports and migrate category statuses')]
class RolloutAutomaticPriceListImports extends Command
{
    public function handle(): int
    {
        $preparationIds = [];
        $activationIds = [];

        DB::transaction(function () use (&$preparationIds, &$activationIds): void {
            $legacyDuplicates = PriceListImport::query()
                ->where('status', PriceListImportStatus::AwaitingDuplicateConfirmation)
                ->lockForUpdate()
                ->get();
            foreach ($legacyDuplicates as $import) {
                $import->update(['status' => PriceListImportStatus::Pending]);
                $preparationIds[] = $import->id;
            }

            $legacyPreviews = PriceListImport::query()
                ->where('status', PriceListImportStatus::Preview)
                ->lockForUpdate()
                ->get();
            foreach ($legacyPreviews as $import) {
                $import->update(['summary' => array_replace($import->summary ?? [], ['automatic_rollout_queued' => true])]);
                $activationIds[] = $import->id;
            }

            PriceListImportRow::query()
                ->whereIn('categorization_status', ['unmatched', 'review_required'])
                ->each(function (PriceListImportRow $row): void {
                    $warnings = array_values(array_diff($row->warnings ?? [], [
                        'Категория товара не распознана.',
                        'Категории товара требуют проверки.',
                    ]));
                    $row->update([
                        'categorization_status' => $row->categorization_status === 'unmatched' ? 'uncategorized' : 'attention_needed',
                        'warnings' => $warnings,
                        'disposition' => $row->disposition === PriceListRowDisposition::Warning && $warnings === []
                            ? PriceListRowDisposition::Valid
                            : $row->disposition,
                    ]);
                });

            PriceListImport::query()->whereHas('rows')->each(function (PriceListImport $import): void {
                $counts = $import->rows()->selectRaw('disposition, count(*) as aggregate')->groupBy('disposition')->pluck('aggregate', 'disposition');
                $import->update([
                    'valid_rows' => (int) ($counts[PriceListRowDisposition::Valid->value] ?? 0) + (int) ($counts[PriceListRowDisposition::Warning->value] ?? 0),
                    'error_rows' => (int) ($counts[PriceListRowDisposition::Error->value] ?? 0),
                    'warning_rows' => (int) ($counts[PriceListRowDisposition::Warning->value] ?? 0),
                    'skipped_rows' => (int) ($counts[PriceListRowDisposition::Skipped->value] ?? 0),
                ]);
            });
        });

        foreach ($preparationIds as $importId) {
            PreparePriceListImport::dispatch(PriceListImport::findOrFail($importId))->onQueue(config('price-list-imports.queue'));
        }
        foreach ($activationIds as $importId) {
            app(ActivationDispatcher::class)->dispatch(PriceListImport::findOrFail($importId));
        }

        $this->info('Legacy price-list imports and category statuses were rolled forward.');

        return self::SUCCESS;
    }
}
