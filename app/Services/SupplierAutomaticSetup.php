<?php

namespace App\Services;

use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\SupplierSynchronization;
use Illuminate\Support\Facades\DB;

class SupplierAutomaticSetup
{
    public function __construct(private AuditLogger $audit) {}

    /** @return array{status: string, reason: string} */
    public function configure(Organization $supplier, bool $apply = false): array
    {
        return DB::transaction(function () use ($supplier, $apply): array {
            $supplier = Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->first();
            $partners = ExternalSupplierPartner::where('supplier_organization_id', $supplier->id)->orderBy('id')->lockForUpdate()->get();
            if ($reason = $this->blockingReason($supplier, $configuration)) {
                return $this->result($supplier, $reason);
            }
            if ($partners->isEmpty()) {
                return $this->result($supplier, 'unmapped');
            }
            foreach ($partners as $partner) {
                if ($partner->profile_configuration !== null) {
                    continue;
                }
                $partner->fill(['profile_configuration' => ExternalPriceWorkbook::profile(), 'profile_active' => true, 'profile_version' => $partner->profile_version + 1]);
                if ($apply) {
                    $before = $partner->getRawOriginal();
                    $partner->save();
                    $this->audit->log('supplier_external_profile.automatic_setup', $partner, $before, $partner->toArray());
                }
            }
            if (! $partners->contains(fn (ExternalSupplierPartner $partner): bool => $partner->profile_active && filled($partner->profile_configuration))) {
                return $this->result($supplier, 'missing_profile');
            }
            $configuration ??= new SupplierSynchronization(['supplier_organization_id' => $supplier->id, 'sources' => SupplierSynchronization::defaultSources()]);
            $before = $configuration->toArray();
            $configuration->fill(['selection_mode' => 'daily_versions', 'automatic_enabled' => true, 'paused' => false, 'settings_confirmed' => true, 'fetch_interval_minutes' => 60, 'selected_source' => null, 'workflow_policy' => null]);
            if ($apply && $configuration->isDirty()) {
                $configuration->fill(['version' => ($configuration->version ?? 0) + 1, 'next_check_at' => now()])->save();
                $this->audit->log('supplier_sync.automatic_setup', $configuration, $before, $configuration->toArray());
            }

            return ['status' => 'enabled', 'reason' => 'hourly_daily_versions'];
        }, 3);
    }

    /** @return array{status: string, reason: string} */
    public function status(Organization $supplier): array
    {
        $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->first();
        if ($reason = $this->blockingReason($supplier, $configuration)) {
            return $this->result($supplier, $reason);
        }
        $partners = ExternalSupplierPartner::where('supplier_organization_id', $supplier->id)->get();
        $reason = match (true) {
            $partners->isEmpty() => 'unmapped',
            ! $partners->contains(fn (ExternalSupplierPartner $partner): bool => $partner->profile_active && filled($partner->profile_configuration)) => 'missing_profile',
            ! $configuration?->automatic_enabled => 'automatic_disabled',
            default => 'hourly_daily_versions',
        };

        return ['status' => $reason === 'hourly_daily_versions' ? 'enabled' : 'blocked', 'reason' => $reason];
    }

    private function blockingReason(Organization $supplier, ?SupplierSynchronization $configuration): ?string
    {
        return match (true) {
            $supplier->status !== 'active' => 'supplier_not_active',
            ! $supplier->external_fetch_enabled => $supplier->external_fetch_exclusion_reason ?: 'external_fetch_disabled',
            (bool) $configuration?->paused => 'administrator_paused',
            $configuration?->settings_confirmed && ! $configuration->automatic_enabled => 'administrator_disabled',
            default => null,
        };
    }

    /** @return array{status: string, reason: string} */
    private function result(Organization $supplier, string $reason): array
    {
        return ['status' => $supplier->status === 'active' && ! $supplier->external_fetch_enabled ? 'excluded' : 'blocked', 'reason' => $reason];
    }
}
