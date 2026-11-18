<?php

namespace App\Console\Commands;

use App\Enums\OrganizationType;
use App\Enums\PriceListImportStatus;
use App\Models\ExternalDownload;
use App\Models\ExternalListingRun;
use App\Models\ExternalServiceState;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSyncRun;
use App\Models\SupplierVersionClaim;
use App\Services\SupplierAutomaticSetup;
use App\Services\SupplierEmailExclusions;
use App\Services\SupplierInboxEvidence;
use App\Services\SupplierVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RolloutSupplierVersions extends Command
{
    protected $signature = 'suppliers:rollout-versions {--prepare : Pause dispatch and backfill after work drains} {--invalidate-external : Invalidate unfinished old-policy external work} {--enable : Enable dispatch after backfill and a complete listing dry run}';

    protected $description = 'Safely reconcile supplier publication days and switch to the approved daily-version workflow';

    public function handle(SupplierVersions $versions, SupplierInboxEvidence $inbox, SupplierEmailExclusions $exclusions): int
    {
        return Cache::lock('supplier-imports:version-rollout', 7200)->block(1, fn (): int => $this->rollout($versions, $inbox, $exclusions));
    }

    private function rollout(SupplierVersions $versions, SupplierInboxEvidence $inbox, SupplierEmailExclusions $exclusions): int
    {
        if ($this->option('enable')) {
            $sources = ['icorp', 'pricelist_tj'];
            if (! in_array($versions->workflowStatus(), ['prepared', 'enabled'], true) || ExternalListingRun::where('scan_hour', now()->startOfHour())->whereIn('source', $sources)->where('status', 'completed')->count() !== 2
                || ExternalServiceState::whereIn('source', $sources)->where('status', 'paused')->exists()) {
                $this->error('Backfill and complete current-hour listing-only scans are required. Restore the iCorp connection before enabling dispatch.');

                return self::FAILURE;
            }
            DB::table('supplier_import_workflow_states')->where('workflow', 'daily_versions')->update(['status' => 'enabled', 'enabled_at' => now(), 'updated_at' => now()]);
            Cache::forever('supplier-imports:dispatch-paused', false);
            $this->info('Daily supplier-version dispatch enabled.');

            return self::SUCCESS;
        }
        if (! $this->option('prepare')) {
            $this->line('Use --prepare, then suppliers:scan-listings (listing-only), then --enable.');

            return self::SUCCESS;
        }
        if ($versions->workflowStatus() === 'enabled') {
            $this->error('Daily-version rollout is already enabled. Use supplier settings for subsequent changes.');

            return self::FAILURE;
        }
        DB::table('supplier_import_workflow_states')->updateOrInsert(['workflow' => 'daily_versions'], ['status' => 'preparing', 'updated_at' => now()]);
        Cache::forever('supplier-imports:dispatch-paused', true);
        if ($this->option('invalidate-external')) {
            Organization::where(fn ($query) => $query->whereIn('id', ExternalDownload::select('supplier_organization_id'))->orWhereIn('id', PriceListImport::whereIn('source_type', ['icorp', 'pricelist_tj'])->select('supplier_organization_id')))->eachById(function (Organization $supplier): void {
                DB::transaction(function () use ($supplier): void {
                    Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                    ExternalDownload::where('supplier_organization_id', $supplier->id)->whereNotIn('status', ['completed', 'failed', 'cancelled'])->update(['status' => 'cancelled', 'reason' => 'policy_rollout']);
                    PriceListImport::where('supplier_organization_id', $supplier->id)->whereIn('source_type', ['icorp', 'pricelist_tj'])
                        ->whereIn('status', [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Preview, PriceListImportStatus::Committing])
                        ->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'policy_rollout', 'failure_message' => 'Old-policy work invalidated', 'failed_at' => now()]);
                });
            });
            SupplierSyncRun::whereNotNull('active_supplier_id')->update(['active_supplier_id' => null, 'status' => 'skipped', 'reason' => 'policy_rollout', 'finished_at' => now()]);
        }
        if (PriceListImport::whereIn('status', [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Committing])->exists()
            || ExternalDownload::whereIn('status', ['pending', 'downloading', 'retry'])->exists()) {
            $this->error('Dispatch is paused. Drain active imports or invalidate old external work before retrying preparation.');

            return self::FAILURE;
        }
        PriceListImport::whereIn('status', [PriceListImportStatus::Completed, PriceListImportStatus::Superseded])->orderBy('id')->eachById(function (PriceListImport $import) use ($versions): void {
            DB::transaction(function () use ($import, $versions): void {
                $supplier = Organization::whereKey($import->supplier_organization_id)->lockForUpdate()->firstOrFail();
                $metadata = $import->source_metadata ?? [];
                $reliable = in_array($import->source_type->value, ['icorp', 'pricelist_tj'], true)
                    || isset($metadata['date_confirmed_by'])
                    || in_array($metadata['publication_origin'] ?? null, ['cell', 'filename', 'listing', 'supplied'], true);
                $publication = $versions->publication($import->original_filename, $import->profile_snapshot ?? [], $import->received_at ?? $import->created_at, $reliable ? $import->source_published_at : null);
                if (! $reliable && $publication['origin'] === 'arrival') {
                    try {
                        $workbookPublication = $versions->workbookPublication($import);
                        if ($workbookPublication['date']) {
                            $publication = $workbookPublication;
                        } elseif (($import->profile_snapshot['inventory']['source'] ?? null) === 'cell' && $import->inventory_at) {
                            $publication = ['date' => $import->inventory_at, 'precision' => 'day', 'origin' => 'cell'];
                        }
                    } catch (\Throwable) {
                        if (($import->profile_snapshot['inventory']['source'] ?? null) === 'cell' && $import->inventory_at) {
                            $publication = ['date' => $import->inventory_at, 'precision' => 'day', 'origin' => 'cell'];
                        }
                    }
                }
                $metadata['version_reconciliation'] ??= ['original_published_at' => $import->source_published_at?->toIso8601String(), 'original_precision' => $import->source_date_precision, 'original_inventory_at' => $import->inventory_at?->toIso8601String(), 'original_publication_origin' => $metadata['publication_origin'] ?? null, 'origin' => $publication['origin'], 'reconciled_at' => now()->toIso8601String()];
                $metadata['publication_origin'] = $publication['origin'];
                $import->update(['source_published_at' => $publication['date'], 'source_date_precision' => $reliable ? ($import->source_date_precision ?? $publication['precision']) : $publication['precision'], 'source_metadata' => $metadata]);
                $day = $versions->day($publication['date']);
                $claim = SupplierVersionClaim::firstOrCreate(['supplier_organization_id' => $supplier->id, 'published_day' => $day], ['source' => $import->source_type->value]);
                if (! $claim->completed_at || $import->activated_at?->gte($claim->completed_at)) {
                    $claim->update(['state' => 'completed', 'price_list_import_id' => $import->id, 'completed_at' => $import->activated_at ?? $import->created_at]);
                }
                if (! $supplier->successful_price_list_day || $day > $supplier->successful_price_list_day) {
                    $supplier->update(['successful_price_list_day' => $day]);
                }
            }, 3);
        });
        $inbox->reconcileStored();
        $exclusions->assess(seed: true);
        $exclusions->seedApprovedHistory();
        Organization::where('type', OrganizationType::Wholesaler)->eachById(function (Organization $supplier): void {
            app(SupplierAutomaticSetup::class)->configure($supplier, true);
        });
        DB::table('supplier_import_workflow_states')->where('workflow', 'daily_versions')->update(['status' => 'prepared', 'prepared_at' => now(), 'updated_at' => now()]);
        Cache::forever('supplier-imports:versions-reconciled', true);
        $this->info('Publication days and exclusions reconciled; dispatch remains paused. Run suppliers:scan-listings.');

        return self::SUCCESS;
    }
}
