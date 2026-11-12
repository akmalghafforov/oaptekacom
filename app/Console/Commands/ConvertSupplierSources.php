<?php

namespace App\Console\Commands;

use App\Enums\PriceListImportStatus;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use App\Services\AuditLogger;
use App\Services\SupplierImportSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConvertSupplierSources extends Command
{
    protected $signature = 'suppliers:convert-sources
        {--apply : Convert configurations; requires paused dispatch and drained workers}
        {--rollback : Restore saved configuration, rejecting intervening edits}
        {--pause : Stop new mailbox and synchronization dispatch before draining workers}
        {--resume : Resume dispatch after checking conversion counts}
        {--supplier= : Restrict inventory or conversion to a supplier ID}';

    protected $description = 'Inventory, convert and restore exclusive supplier source selections (dry run by default)';

    public function handle(SupplierImportSettings $settings, AuditLogger $audit): int
    {
        if (collect(['apply', 'rollback', 'pause', 'resume'])->filter(fn (string $option): bool => (bool) $this->option($option))->count() > 1) {
            $this->error('Use one action at a time.');

            return self::FAILURE;
        }
        if ($this->option('pause')) {
            Cache::forever('supplier-imports:dispatch-paused', true);
            $this->info('New dispatch paused. Drain import workers before --apply; restart workers with the new code before --resume.');

            return self::SUCCESS;
        }
        if ($this->option('resume')) {
            $remaining = SupplierSynchronization::whereNull('source_conversion')->whereNull('selected_source')->whereNull('selection_mode')->count();
            if ($remaining > 0) {
                $this->error($remaining.' configurations still require conversion. Run the dry run and --apply first.');

                return self::FAILURE;
            }
            Cache::forget('supplier-imports:dispatch-paused');
            $this->info('Dispatch resumed. Unselected and unavailable configurations remain blocked.');

            return self::SUCCESS;
        }
        $mutating = $this->option('apply') || $this->option('rollback');
        if ($mutating && ! Cache::get('supplier-imports:dispatch-paused', false)) {
            $this->error('Run --pause and drain active import work before changing configurations.');

            return self::FAILURE;
        }
        $failed = false;
        $counts = ['converted' => 0, 'blocked' => 0, 'unchanged' => 0, 'restored' => 0];
        SupplierSynchronization::when($this->option('supplier'), fn ($query) => $query->where('supplier_organization_id', $this->option('supplier')))
            ->eachById(function (SupplierSynchronization $observed) use ($settings, $audit, $mutating, &$counts, &$failed): void {
                DB::transaction(function () use ($observed, $settings, $audit, $mutating, &$counts, &$failed): void {
                    $supplier = Organization::whereKey($observed->supplier_organization_id)->lockForUpdate()->firstOrFail();
                    $configuration = SupplierSynchronization::whereKey($observed->id)->lockForUpdate()->firstOrFail();
                    if ($configuration->version !== $observed->version) {
                        $this->warn($supplier->id.': configuration_changed; rerun.');
                        $failed = true;

                        return;
                    }
                    $unfinished = PriceListImport::where('supplier_organization_id', $supplier->id)
                        ->whereIn('status', [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Committing, PriceListImportStatus::Preview])->count();
                    $working = PriceListImport::where('supplier_organization_id', $supplier->id)->whereIn('status', [PriceListImportStatus::Processing, PriceListImportStatus::Committing])->exists()
                        || SupplierSyncRun::where('active_supplier_id', $supplier->id)->where('lease_expires_at', '>', now())->exists();
                    if ($mutating && $working) {
                        $this->warn($supplier->id.': active work must drain; rerun.');
                        $failed = true;

                        return;
                    }
                    if ($this->option('rollback')) {
                        $saved = $configuration->source_conversion;
                        if (! isset($saved['before']) || isset($saved['rolled_back_at'])) {
                            $counts['unchanged']++;

                            return;
                        }
                        if ($configuration->version !== $saved['converted_version']) {
                            $this->warn($supplier->id.': intervening configuration changes; rollback refused.');
                            $failed = true;

                            return;
                        }
                        $before = $configuration->toArray();
                        $restoredVersion = $configuration->version + 1;
                        $configuration->fill($saved['before'])->forceFill(['version' => $restoredVersion, 'source_conversion' => [...$saved, 'rolled_back_at' => now()->toIso8601String()]])->save();
                        $audit->log('supplier_sync.source_conversion_rolled_back', $configuration, $before, $configuration->toArray());
                        $counts['restored']++;

                        return;
                    }
                    if ($configuration->usesNewestAvailable()) {
                        $counts['unchanged']++;

                        return;
                    }
                    if ($configuration->source_conversion !== null || $configuration->selected_source !== null) {
                        $counts['unchanged']++;
                        $reason = $settings->readinessReason($supplier, $configuration->selected_source, true);
                        if ($reason !== null) {
                            $counts['blocked']++;
                        }
                        $this->line($supplier->id.': already configured; selected='.($configuration->selected_source ?? 'none').'; readiness='.($reason ?? 'ready').'; unfinished='.$unfinished);

                        return;
                    }
                    [$selection, $rankingReason] = $this->selection($configuration->sources);
                    $reason = $rankingReason ?? $settings->readinessReason($supplier, $selection, true);
                    $this->line($supplier->id.': selected='.($selection ?? 'none').'; readiness='.($reason ?? 'ready').'; settings_confirmed='.(int) $configuration->settings_confirmed.'; unfinished='.$unfinished);
                    if ($reason !== null) {
                        $counts['blocked']++;
                    }
                    if (! $this->option('apply')) {
                        return;
                    }
                    $before = $configuration->only(['selected_source', 'sources', 'automatic_enabled', 'paused', 'fetch_interval_minutes', 'freshness_hours', 'settings_confirmed', 'next_check_at', 'version']);
                    $configuration->update(['selected_source' => $selection, 'version' => $configuration->version + 1,
                        'source_conversion' => ['before' => $before, 'converted_version' => $configuration->version + 1, 'reason' => $reason, 'converted_at' => now()->toIso8601String()]]);
                    PriceListImport::where('supplier_organization_id', $supplier->id)->whereIn('source_type', ['email', 'icorp', 'pricelist_tj'])
                        ->where('status', PriceListImportStatus::Pending)->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'source_conversion', 'failure_message' => 'configuration_changed', 'failed_at' => now()]);
                    SupplierSyncRun::where('active_supplier_id', $supplier->id)->update(['status' => 'skipped', 'reason' => 'configuration_changed', 'active_supplier_id' => null, 'finished_at' => now()]);
                    $audit->log('supplier_sync.source_converted', $configuration, $before, $configuration->toArray());
                    $counts['converted']++;
                }, 3);
            });
        $this->info(json_encode($counts, JSON_THROW_ON_ERROR));
        if ($mutating) {
            $this->warn('Dispatch remains paused. Review blocked suppliers and counts, restart workers, then run --resume.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{?string, ?string} */
    private function selection(mixed $sources): array
    {
        if (! is_array($sources) || array_diff(array_keys($sources), ['email', 'icorp', 'pricelist_tj']) !== [] || count($sources) !== 3) {
            return [null, 'invalid_ranking'];
        }
        $priorities = [];
        foreach ($sources as $source) {
            if (! is_array($source) || ! array_key_exists('enabled', $source) || ! in_array($source['enabled'], [true, false, 0, 1, '0', '1'], true)
                || ! isset($source['priority']) || ! in_array($source['priority'], [1, 2, 3, '1', '2', '3'], true)) {
                return [null, 'invalid_ranking'];
            }
            $priorities[] = (int) $source['priority'];
        }
        if (count(array_unique($priorities)) !== 3) {
            return [null, 'ambiguous_ranking'];
        }
        $selected = collect($sources)->filter(fn (array $source): bool => (bool) $source['enabled'])->sortBy('priority')->keys()->first();

        return [$selected, $selected === null ? 'no_enabled_source' : null];
    }
}
