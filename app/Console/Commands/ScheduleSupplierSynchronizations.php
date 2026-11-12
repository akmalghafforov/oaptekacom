<?php

namespace App\Console\Commands;

use App\Enums\PriceListImportStatus;
use App\Jobs\DownloadExternalPricePage;
use App\Jobs\NotifyExternalServiceIncident;
use App\Jobs\PreparePriceListImport;
use App\Jobs\RefreshExternalSupplierDirectory;
use App\Jobs\SynchronizeSupplier;
use App\Models\ExternalDownload;
use App\Models\ExternalServiceIncident;
use App\Models\ExternalServiceState;
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
        if (Cache::get('supplier-imports:dispatch-paused', false)) {
            $this->warn('Supplier import dispatch is paused for source conversion.');

            return self::SUCCESS;
        }
        if ($this->option('directories')) {
            foreach (['icorp', 'pricelist_tj'] as $source) {
                RefreshExternalSupplierDirectory::dispatch($source)->onQueue(config('price-list-imports.queue'));
            }

            return self::SUCCESS;
        }
        ExternalServiceState::whereIn('source', ['icorp', 'pricelist_tj'])->whereNotNull('directory_progress')
            ->where(fn ($query) => $query->whereNull('directory_lease_expires_at')->orWhere('directory_lease_expires_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->each(fn ($state) => RefreshExternalSupplierDirectory::dispatch($state->source)->onQueue(config('price-list-imports.queue')));
        ExternalDownload::whereIn('status', ['pending', 'downloading', 'retry', 'cooldown', 'paused'])
            ->where(fn ($query) => $query->whereNull('worker_expires_at')->orWhere('worker_expires_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->eachById(function (ExternalDownload $download): void {
                $state = ExternalServiceState::findOrFail($download->source);
                if ($state->status === 'paused' || $state->cooldown_until?->isFuture()) {
                    return;
                }
                if (in_array($download->status, ['cooldown', 'paused'], true)) {
                    $download->update(['status' => 'retry', 'retry_count' => 0]);
                }
                DownloadExternalPricePage::dispatch($download->id)->onQueue(config('price-list-imports.queue'));
            });
        ExternalServiceIncident::whereNull('notified_at')
            ->where(fn ($query) => $query->whereNull('notification_attempted_at')->orWhere('notification_attempted_at', '<=', now()->subMinutes(20)))
            ->eachById(fn ($incident) => NotifyExternalServiceIncident::dispatch($incident->id)->onQueue(config('price-list-imports.queue')));
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
            ->where('updated_at', '<', now()->subMinutes(15))->eachById(function (PriceListImport $import) use ($service): void {
                $lock = Cache::lock('laravel-queue-overlap:price-list-stage:'.$import->id, 140);
                if ($lock->get()) {
                    try {
                        if ($service->recoverStaleImport($import)) {
                            return;
                        }
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
        SupplierSyncRun::whereNotNull('active_supplier_id')->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))->where(function ($query): void {
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
