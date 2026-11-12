<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\SupplierSynchronization;
use App\Services\PriceList\ProfileValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierImportSettings
{
    public function __construct(private AuditLogger $audit, private ProfileValidator $validator) {}

    /** @param array<string, mixed> $data */
    public function update(Organization $supplier, array $data): void
    {
        DB::transaction(function () use ($supplier, $data): void {
            $supplier = Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            abort_unless($supplier->type === OrganizationType::Wholesaler, 404);
            $configuration = SupplierSynchronization::firstOrNew(['supplier_organization_id' => $supplier->id]);
            if ((int) $data['expected_version'] !== (int) ($configuration->version ?? 0)) {
                throw ValidationException::withMessages(['expected_version' => 'Настройки уже изменились. Обновите страницу и повторите сохранение.']);
            }
            $source = $data['selected_source'];
            $mapped = $source === 'email' || ExternalSupplierPartner::where('supplier_organization_id', $supplier->id)->where('source', $source)->exists();
            if (! $mapped && $source !== $configuration->selected_source) {
                throw ValidationException::withMessages(['selected_source' => 'Сначала подтвердите сопоставление этого источника с поставщиком.']);
            }
            if ($data['automatic_enabled'] && (! $data['paused'] || ! $configuration->automatic_enabled)) {
                if ($this->readinessReason($supplier, $source) !== null) {
                    throw ValidationException::withMessages(['selected_source' => 'Сначала подтвердите сопоставление и настройте активный профиль источника.']);
                }
            }
            $before = $configuration->toArray();
            $sources = collect(SupplierSynchronization::defaultSources())->map(fn (array $defaults, string $name): array => array_replace($defaults, is_array($configuration->sources[$name] ?? null) ? $configuration->sources[$name] : []))->all();
            foreach ($data['sources'] as $name => $settings) {
                $sources[$name]['priority'] = (int) $settings['priority'];
            }
            $configuration->fill(array_intersect_key($data, array_flip(['selected_source', 'automatic_enabled', 'paused', 'fetch_interval_minutes', 'freshness_hours'])))
                ->forceFill(['sources' => $sources, 'version' => ($configuration->version ?? 0) + 1, 'next_check_at' => now(), 'settings_confirmed' => true])->save();
            $this->audit->log('supplier_sync.configured', $configuration, $before, $configuration->toArray());
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateProfile(int $supplierId, ExternalSupplierPartner $partner, array $data): void
    {
        DB::transaction(function () use ($supplierId, $partner, $data): void {
            $supplier = Organization::whereKey($supplierId)->lockForUpdate()->firstOrFail();
            abort_unless($supplier->type === OrganizationType::Wholesaler, 404);
            $partner = ExternalSupplierPartner::whereKey($partner->id)->lockForUpdate()->firstOrFail();
            if ($partner->supplier_organization_id !== $supplierId || (int) $data['expected_supplier_organization_id'] !== $supplierId
                || (int) $data['expected_profile_version'] !== $partner->profile_version
                || $data['expected_source'] !== $partner->source || $data['expected_external_id'] !== $partner->external_id) {
                throw ValidationException::withMessages(['expected_profile_version' => 'Сопоставление или профиль уже изменились. Обновите страницу и проверьте поставщика.']);
            }
            $profile = ExternalPriceWorkbook::profile([
                'matching_strategy' => $data['matching_strategy'],
                'date_formats' => array_values(array_filter(array_map('trim', explode(',', $data['date_formats'])))),
                'automatic' => array_intersect_key($data, array_flip(['minimum_valid_rows', 'maximum_error_rows', 'maximum_error_percentage'])),
            ]);
            $profile = $this->validator->validate($profile);
            $before = $partner->only(['profile_configuration', 'profile_active', 'profile_version']);
            $partner->update(['profile_configuration' => $profile, 'profile_active' => $data['profile_active'], 'profile_version' => $partner->profile_version + 1]);
            SupplierSynchronization::where('supplier_organization_id', $supplierId)->increment('version');
            $this->audit->log('supplier_sync.profile_updated', $partner, $before, $partner->only(['profile_configuration', 'profile_active', 'profile_version']));
        }, 3);
    }

    public function readinessReason(Organization $supplier, ?string $source, bool $checkCredentials = false): ?string
    {
        if ($source === 'email') {
            $profile = $supplier->importProfile()->first();
            $configuration = $profile?->configuration;
            $active = $profile?->is_active;
        } elseif (in_array($source, ['icorp', 'pricelist_tj'], true)) {
            $partner = ExternalSupplierPartner::where('supplier_organization_id', $supplier->id)->where('source', $source)->first();
            if (! $partner) {
                return 'unmapped';
            }
            $configuration = $partner->profile_configuration;
            $active = $partner->profile_active;
        } else {
            return 'selection_required';
        }
        if (! $active || ! $configuration) {
            return 'missing_profile';
        }
        try {
            $this->validator->validate($configuration);
        } catch (ValidationException) {
            return 'missing_profile';
        }

        if ($checkCredentials && in_array($source, ['icorp', 'pricelist_tj'], true)) {
            $credentials = $source === 'icorp' ? ['firm_id', 'phone', 'password'] : ['phone', 'password'];
            if (collect($credentials)->contains(fn (string $key): bool => ! filled(config('external-prices.'.$source.'.'.$key)))) {
                return 'credentials_unavailable';
            }
        }

        return null;
    }
}
