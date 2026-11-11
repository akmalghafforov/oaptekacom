<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Support\Facades\Validator;

class ExternalSupplierEligibility
{
    /**
     * @param  list<array{source: string, external_id: string, name: string, phones?: list<string>}>  $partners
     * @param  list<array{name?: string, phones?: list<string>, supplier_organization_id?: int}>  $excludedSuppliers
     * @return list<array{source: string, external_id: string, name: string, outcome: string, reason: string, supplier_organization_ids: list<int>}>
     */
    public function review(array $partners, array $excludedSuppliers): array
    {
        Validator::make(['partners' => $partners, 'excluded' => $excludedSuppliers], [
            'partners' => ['present', 'array', 'list'],
            'partners.*' => ['required', 'array:source,external_id,name,phones'],
            'partners.*.source' => ['required', 'in:icorp,pricelist_tj'],
            'partners.*.external_id' => ['required', 'string', 'max:255'],
            'partners.*.name' => ['required', 'string', 'max:255'],
            'partners.*.phones' => ['sometimes', 'array', 'list'],
            'partners.*.phones.*' => ['required', 'string', 'max:50'],
            'excluded' => ['present', 'array', 'list'],
            'excluded.*' => ['required', 'array:name,phones,supplier_organization_id'],
            'excluded.*.name' => ['required_without_all:excluded.*.phones,excluded.*.supplier_organization_id', 'string', 'max:255'],
            'excluded.*.phones' => ['sometimes', 'array', 'list', 'min:1'],
            'excluded.*.phones.*' => ['required', 'string', 'max:50'],
            'excluded.*.supplier_organization_id' => ['sometimes', 'integer', 'min:1', 'exists:organizations,id'],
        ])->validate();

        $suppliers = Organization::query()->where('type', OrganizationType::Wholesaler)
            ->with('importProfile:id,supplier_organization_id,is_active')
            ->orderBy('id')->get(['id', 'name', 'phone', 'additional_phones', 'status']);

        return array_map(function (array $partner) use ($suppliers, $excludedSuppliers): array {
            $matches = $suppliers->filter(fn (Organization $supplier): bool => $this->matches($partner, [
                'name' => $supplier->name,
                'phones' => array_filter([$supplier->phone, ...($supplier->additional_phones ?? [])]),
            ]));
            $excluded = collect($excludedSuppliers)->contains(function (array $supplier) use ($partner, $matches): bool {
                return $this->matches($partner, $supplier)
                    || (isset($supplier['supplier_organization_id']) && $matches->contains('id', (int) $supplier['supplier_organization_id']));
            });
            [$outcome, $reason] = match (true) {
                $excluded => ['skip', 'already_sends_price_list'],
                $matches->contains(fn (Organization $supplier): bool => $supplier->importProfile?->is_active === true) => ['skip', 'active_import_profile'],
                $matches->count() > 1 => ['review', 'ambiguous_supplier'],
                $matches->contains(fn (Organization $supplier): bool => $supplier->status !== 'active') => ['review', 'inactive_supplier'],
                $matches->isEmpty() => ['eligible', 'supplier_not_registered'],
                default => ['eligible', 'no_active_import_profile'],
            };

            return [
                'source' => $partner['source'],
                'external_id' => $partner['external_id'],
                'name' => $partner['name'],
                'outcome' => $outcome,
                'reason' => $reason,
                'supplier_organization_ids' => $matches->modelKeys(),
            ];
        }, $partners);
    }

    /**
     * @param  array{name?: string, phones?: array<string>}  $partner
     * @param  array{name?: string, phones?: array<string>, supplier_organization_id?: int}  $supplier
     */
    private function matches(array $partner, array $supplier): bool
    {
        $name = $this->normalizeName($partner['name'] ?? '');
        if ($name !== '' && $name === $this->normalizeName($supplier['name'] ?? '')) {
            return true;
        }

        $partnerPhones = array_filter(array_map($this->normalizePhone(...), $partner['phones'] ?? []));
        $supplierPhones = array_filter(array_map($this->normalizePhone(...), $supplier['phones'] ?? []));

        return array_intersect($partnerPhones, $supplierPhones) !== [];
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name));
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) === 9) {
            return '992'.$digits;
        }
        if (strlen($digits) === 14 && str_starts_with($digits, '00992')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) === 12 && str_starts_with($digits, '992') ? $digits : null;
    }
}
