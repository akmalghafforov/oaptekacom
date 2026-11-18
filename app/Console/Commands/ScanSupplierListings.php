<?php

namespace App\Console\Commands;

use App\Models\ExternalListingRun;
use App\Models\SupplierSynchronization;
use App\Services\RenderedSupplierListing;
use App\Services\SupplierInboxEvidence;
use App\Services\SupplierSynchronizationService;
use App\Services\SupplierVersions;
use Illuminate\Console\Command;

class ScanSupplierListings extends Command
{
    protected $signature = 'suppliers:scan-listings {--dispatch : Queue eligible imports after the listing scan}';

    protected $description = 'Scan rendered supplier listings once per source/hour; listing-only by default';

    public function handle(RenderedSupplierListing $scanner, SupplierVersions $versions, SupplierInboxEvidence $inbox): int
    {
        if (! $versions->enabled()) {
            $this->warn('Run suppliers:rollout-versions --prepare before using the listing scanner.');

            return self::FAILURE;
        }
        $inbox->reconcileStored();
        foreach (['pricelist_tj', 'icorp'] as $source) {
            $run = ExternalListingRun::firstOrCreate(['source' => $source, 'scan_hour' => now()->startOfHour()]);
            if ($this->option('dispatch') && ! $versions->paused()) {
                $run->update(['dispatch_requested' => true]);
            }
        }
        foreach (['pricelist_tj', 'icorp'] as $source) {
            $run = $scanner->scan($source);
            $this->line($source.': '.($run?->status ?? 'busy'));
        }
        if ($this->option('dispatch') && ExternalListingRun::where('scan_hour', now()->startOfHour())->whereIn('source', ['icorp', 'pricelist_tj'])->whereIn('status', ['completed', 'failed'])->count() === 2 && ! app(SupplierVersions::class)->paused()) {
            SupplierSynchronization::where('automatic_enabled', true)->where('paused', false)->with('supplier')->eachById(function (SupplierSynchronization $configuration): void {
                if ($configuration->supplier->external_fetch_enabled) {
                    app(SupplierSynchronizationService::class)->request($configuration->supplier, 'hourly_listing');
                }
            });
        }

        return self::SUCCESS;
    }
}
