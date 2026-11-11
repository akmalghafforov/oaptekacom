<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Enums\TradeMode;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\SupplierSynchronization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierMappingApproval
{
    public function fingerprint(ExternalSupplierPartner|Organization $record, int $version = 0): string
    {
        $fields = $record instanceof ExternalSupplierPartner
            ? ['id', 'source', 'external_id', 'name', 'phones', 'metadata', 'supplier_organization_id', 'profile_version', 'profile_active', 'profile_configuration']
            : ['id', 'type', 'name', 'city', 'phone', 'additional_phones'];

        return hash_hmac('sha256', json_encode([$record::class, $record->only($fields), $version], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), config('app.key'));
    }

    /** @param array<string, mixed> $data */
    public function approve(array $data): Organization
    {
        return DB::transaction(function () use ($data): Organization {
            $ids = array_map('intval', array_values($data['partner_ids']));
            sort($ids, SORT_NUMERIC);
            $observedSupplierIds = ExternalSupplierPartner::whereIn('id', $ids)->pluck('supplier_organization_id')->filter()->all();
            $supplierId = $data['mapping_mode'] === 'existing' ? (int) $data['supplier_organization_id'] : null;
            $suppliers = Organization::whereIn('id', array_filter([...$observedSupplierIds, $supplierId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $supplier = $supplierId ? $suppliers->get($supplierId) : null;
            if ($supplierId) {
                if (! $supplier || $supplier->type !== OrganizationType::Wholesaler) {
                    $this->stale('supplier_organization_id');
                }
                $version = (int) (SupplierSynchronization::where('supplier_organization_id', $supplierId)->value('version') ?? 0);
                if ((int) ($data['supplier_versions'][$supplierId] ?? -1) !== $version
                    || ! hash_equals($this->fingerprint($supplier, $version), $data['supplier_evidence'][$supplierId] ?? '')) {
                    $this->stale('supplier_organization_id');
                }
            } else {
                $supplier = Organization::create(['name' => $data['name'], 'city' => $data['city'] ?? null, 'phone' => $data['phone'] ?? null,
                    'type' => OrganizationType::Wholesaler, 'status' => 'active', 'supplier_mode' => TradeMode::Supplier->value]);
                app(AuditLogger::class)->log('organization.supplier_created', $supplier, [], $supplier->only(['name', 'city', 'phone', 'type', 'status']));
            }
            $partners = ExternalSupplierPartner::where(function (Builder $query) use ($ids, $supplier): void {
                $query->whereIn('id', $ids)->orWhere('supplier_organization_id', $supplier->id);
            })->orderBy('id')->lockForUpdate()->get();
            $selected = $partners->whereIn('id', $ids);
            if ($selected->count() !== count($ids)) {
                $this->stale('partner_ids');
            }
            if ($selected->pluck('source')->unique()->count() !== $selected->count()
                || $selected->contains(fn (ExternalSupplierPartner $partner): bool => ! in_array($partner->source, ['icorp', 'pricelist_tj'], true))) {
                throw ValidationException::withMessages(['partner_ids' => 'Выберите не более одной записи каждого сервиса.']);
            }
            foreach ($selected as $partner) {
                if ((int) ($data['partner_versions'][$partner->id] ?? 0) !== $partner->profile_version
                    || ! hash_equals($this->fingerprint($partner), $data['partner_evidence'][$partner->id] ?? '')) {
                    $this->stale('partner_ids.'.$partner->source);
                }
                if (($partner->supplier_organization_id && $partner->supplier_organization_id !== $supplier->id)
                    || $partners->contains(fn (ExternalSupplierPartner $other): bool => $other->id !== $partner->id && $other->source === $partner->source && $other->supplier_organization_id === $supplier->id)) {
                    throw ValidationException::withMessages(['partner_ids.'.$partner->source => 'Уже подтверждено другое сопоставление. Обновите страницу и проверьте связи.']);
                }
            }
            $changed = false;
            foreach ($selected as $partner) {
                if ($partner->supplier_organization_id === $supplier->id) {
                    continue;
                }
                $before = $partner->only('supplier_organization_id');
                $partner->update(['supplier_organization_id' => $supplier->id, 'profile_version' => $partner->profile_version + 1]);
                app(AuditLogger::class)->log('supplier_mapping.confirmed', $partner, $before, $partner->only('supplier_organization_id'));
                $changed = true;
            }
            if ($changed) {
                SupplierSynchronization::firstOrCreate(['supplier_organization_id' => $supplier->id], ['sources' => SupplierSynchronization::defaultSources()]);
                SupplierSynchronization::where('supplier_organization_id', $supplier->id)->increment('version');
            }

            return $supplier;
        }, 3);
    }

    private function stale(string $field): never
    {
        throw ValidationException::withMessages([$field => 'Данные изменились после проверки. Обновите страницу и проверьте предположение заново.']);
    }
}
