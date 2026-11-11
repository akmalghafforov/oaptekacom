<?php

namespace App\Console\Commands;

use App\Enums\PriceListImportStatus;
use App\Jobs\PreparePriceListImport;
use App\Jobs\RefreshExternalSupplierDirectory;
use App\Jobs\SynchronizeSupplier;
use App\Models\PriceListImport;
use App\Models\SupplierSyncAttempt;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use App\Services\PriceList\ActivationDispatcher;
use App\Services\SupplierSynchronizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ScheduleSupplierSynchronizations extends Command
{
    protected $signature = 'suppliers:sync {--directories : Refresh both external directories}';

    protected $description = 'Dispatch due supplier synchronizations and recover unfinished runs';

    public function handle(SupplierSynchronizationService $service): int
    {
        if ($this->option('directories')) {
            foreach (['icorp', 'pricelist_tj'] as $source) {
                RefreshExternalSupplierDirectory::dispatch($source)->onQueue(config('price-list-imports.queue'));
            }

            return self::SUCCESS;
        }
        SupplierSyncAttempt::whereNotNull('price_list_import_id')->whereNotIn('status', ['activated', 'failed', 'skipped'])
            ->whereHas('import', fn ($query) => $query->whereIn('status', [PriceListImportStatus::Completed, PriceListImportStatus::Superseded, PriceListImportStatus::Failed]))
            ->with(['import', 'run'])->eachById(function (SupplierSyncAttempt $attempt): void {
                $failed = $attempt->import->status === PriceListImportStatus::Failed;
                $attempt->update(['status' => $failed ? 'failed' : 'activated', 'reason' => $failed ? 'import_failed' : null,
                    'metadata' => array_replace($attempt->metadata ?? [], ['failure_stage' => $attempt->import->failure_stage])]);
                if ($attempt->run->trigger === 'mailbox') {
                    $attempt->run->update(['status' => $failed ? 'failed' : 'completed', 'reason' => $failed ? 'import_failed' : 'activated', 'selected_source' => $failed ? null : 'email', 'finished_at' => now()]);
                }
            });
        PriceListImport::whereIn('source_type', ['icorp', 'pricelist_tj'])
            ->whereIn('status', [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Committing])
            ->where('updated_at', '<', now()->subMinutes(15))->eachById(function (PriceListImport $import): void {
                $lock = Cache::lock('laravel-queue-overlap:price-list-stage:'.$import->id, 140);
                if ($lock->get()) {
                    try {
                        if ($import->status === PriceListImportStatus::Committing) {
                            app(ActivationDispatcher::class)->dispatch($import);
                        } else {
                            PreparePriceListImport::dispatch($import)->onQueue(config('price-list-imports.queue'));
                        }
                    } finally {
                        $lock->release();
                    }
                }
            });
        SupplierSyncRun::whereNotNull('active_supplier_id')->where(function ($query): void {
            $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now());
        })->eachById(fn (SupplierSyncRun $run) => SynchronizeSupplier::dispatch($run->id)->onQueue(config('price-list-imports.queue')));
        SupplierSynchronization::where('automatic_enabled', true)->where('paused', false)
            ->where(fn ($query) => $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->with('supplier')->eachById(fn (SupplierSynchronization $config) => $service->request($config->supplier, 'automatic'));
        PriceListImport::where('source_type', 'email')->whereNotNull('source_metadata')->where('status', PriceListImportStatus::Preview)
            ->eachById(fn (PriceListImport $import) => app(ActivationDispatcher::class)->dispatch($import));

        return self::SUCCESS;
    }
}
