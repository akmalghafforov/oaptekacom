<?php

namespace App\Services;

use App\Models\Medicine;
use App\Models\Offer;
use App\Models\ProductCategory;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogSearchService
{
    /** @var array<string, list<int>> */
    private array $fuzzyIds = [];

    /** @param array<string, mixed> $filters */
    public function search(array $filters, ?int $pharmacyOrganizationId = null): CursorPaginator
    {
        $query = $this->offersQuery($filters, true, $pharmacyOrganizationId)
            ->with(['medicine.categories', 'organization']);

        if (($filters['q'] ?? '') !== '') {
            $query->addSelect('matched_medicines.match_rank')->orderBy('match_rank');
        }

        match ($filters['sort'] ?? 'price_asc') {
            'price_desc' => $query->orderByDesc('effective_price')->orderByDesc('offers.id'),
            'updated_desc' => $query->orderByDesc('offers.updated_at')->orderByDesc('offers.id'),
            'name_asc' => $query->join('medicines as sort_medicines', 'sort_medicines.id', '=', 'offers.medicine_id')->addSelect('sort_medicines.normalized_name')->orderBy('normalized_name')->orderBy('offers.id'),
            default => $query->orderBy('effective_price')->orderBy('offers.id'),
        };

        return $query->cursorPaginate((int) config('catalog.page_size'), cursorName: 'cursor', cursor: $filters['cursor'] ?? null);
    }

    /** @param array<string, mixed> $filters */
    public function total(array $filters, ?int $pharmacyOrganizationId = null): int
    {
        return $this->offersQuery($filters, true, $pharmacyOrganizationId)->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{id: int, code: string, label: string, count: int, icon: string}>
     */
    public function facets(array $filters, ?int $pharmacyOrganizationId = null): Collection
    {
        return ProductCategory::query()
            ->select(['product_categories.id', 'product_categories.code', 'product_categories.label', 'product_categories.sort_order'])
            ->selectRaw('count(offers.id) as offer_count')
            ->join('medicine_product_category', 'medicine_product_category.product_category_id', '=', 'product_categories.id')
            ->joinSub(
                $this->offersQuery($filters, false, $pharmacyOrganizationId)->select(['offers.id', 'offers.medicine_id']),
                'offers',
                'offers.medicine_id',
                '=',
                'medicine_product_category.medicine_id',
            )
            ->where('product_categories.is_active', true)
            ->groupBy(['product_categories.id', 'product_categories.code', 'product_categories.label', 'product_categories.sort_order'])
            ->orderByDesc('offer_count')
            ->orderBy('product_categories.sort_order')
            ->orderBy('product_categories.id')
            ->get()
            ->map(fn (ProductCategory $category): array => [
                'id' => $category->id,
                'code' => $category->code,
                'label' => $category->label,
                'count' => (int) $category->getAttribute('offer_count'),
                'icon' => $category->code,
            ]);
    }

    /** @return Collection<int, array{value: string, label: string}> */
    public function cities(array $filters = [], ?int $pharmacyOrganizationId = null): Collection
    {
        return DB::query()->fromSub($this->offersQuery($filters, false, $pharmacyOrganizationId)->select('offers.organization_id'), 'matching_offers')
            ->join('organizations', 'organizations.id', '=', 'matching_offers.organization_id')
            ->whereNotNull('organizations.city')->where('organizations.city', '<>', '')
            ->distinct()->orderBy('organizations.city')->pluck('organizations.city')
            ->map(fn (string $city): array => ['value' => $city, 'label' => $city]);
    }

    /** @return Collection<int, array{id: int, name: string, city: ?string}> */
    public function suppliers(array $filters = [], ?int $pharmacyOrganizationId = null): Collection
    {
        return DB::query()->fromSub($this->offersQuery($filters, false, $pharmacyOrganizationId)->select('offers.organization_id'), 'matching_offers')
            ->join('organizations', 'organizations.id', '=', 'matching_offers.organization_id')
            ->distinct()->orderBy('organizations.name')->get(['organizations.id', 'organizations.name', 'organizations.city'])
            ->map(fn (object $supplier): array => ['id' => (int) $supplier->id, 'name' => $supplier->name, 'city' => $supplier->city]);
    }

    /** @param array<string, mixed> $filters */
    private function offersQuery(array $filters, bool $includeCategory, ?int $pharmacyOrganizationId): Builder
    {
        $effectivePrice = $this->effectivePriceExpression($pharmacyOrganizationId !== null);

        return Offer::query()
            ->select('offers.*')
            ->join('organizations as discount_suppliers', 'discount_suppliers.id', '=', 'offers.organization_id')
            ->when($pharmacyOrganizationId !== null, fn (Builder $query): Builder => $query->leftJoin('pharmacy_supplier_discounts', function ($join) use ($pharmacyOrganizationId): void {
                $join->on('pharmacy_supplier_discounts.supplier_organization_id', '=', 'offers.organization_id')
                    ->where('pharmacy_supplier_discounts.pharmacy_organization_id', '=', $pharmacyOrganizationId);
            }))
            ->selectRaw($effectivePrice.' as effective_price')
            ->selectRaw($pharmacyOrganizationId !== null ? $this->discountPercentExpression().' as applied_supplier_discount_percent' : 'NULL as applied_supplier_discount_percent')
            ->currentCatalog()
            ->when(($filters['q'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $query->joinSub($this->matchingMedicines($filters), 'matched_medicines', 'matched_medicines.id', '=', 'offers.medicine_id');
            })
            ->when(($filters['q'] ?? '') !== '' && ! empty($filters['form']), fn (Builder $query): Builder => $query->whereHas(
                'medicine', fn (Builder $medicineQuery): Builder => $medicineQuery->where('form', $filters['form']),
            ))
            ->when(! empty($filters['cities']), fn (Builder $query): Builder => $query->whereHas(
                'organization',
                fn (Builder $organizationQuery): Builder => $organizationQuery->whereIn('city', $filters['cities']),
            ))
            ->when(! empty($filters['suppliers']), fn (Builder $query): Builder => $query->whereIn('offers.organization_id', $filters['suppliers']))
            ->when(isset($filters['min_price']), fn (Builder $query): Builder => $query->whereRaw($effectivePrice.' >= ?', [$filters['min_price']]))
            ->when(isset($filters['max_price']), fn (Builder $query): Builder => $query->whereRaw($effectivePrice.' <= ?', [$filters['max_price']]))
            ->when($includeCategory && ! empty($filters['category']), fn (Builder $query): Builder => $query->whereHas(
                'medicine.categories',
                fn (Builder $categoryQuery): Builder => $categoryQuery->whereKey($filters['category']),
            ));
    }

    /** @param array<string, mixed> $filters */
    private function matchingMedicines(array $filters): Builder
    {
        $search = mb_strtolower($filters['q']);
        $substring = (bool) ($filters['substring_match'] ?? false);
        [$literalSql, $literalBindings] = $this->literalCondition($search, $substring);
        $matcher = new UnicodeSearchMatcher;
        $cacheKey = json_encode([$search, $substring], JSON_THROW_ON_ERROR);

        if (! array_key_exists($cacheKey, $this->fuzzyIds)) {
            $ids = [];
            if ($matcher->allowedEdits($search) > 0) {
                Medicine::query()->select(['medicines.id', 'medicines.search_text'])
                    ->whereIn('medicines.id', Offer::query()->currentCatalog()->select('offers.medicine_id'))
                    ->whereRaw('NOT ('.$literalSql.')', $literalBindings)
                    ->where(function (Builder $query) use ($matcher, $search): void {
                        foreach ($matcher->segments($search) as $segment) {
                            [$sql, $bindings] = $this->literalCondition($segment, true);
                            $query->orWhereRaw($sql, $bindings);
                        }
                    })
                    ->chunkById(500, function (Collection $medicines) use (&$ids, $matcher, $search, $substring): void {
                        foreach ($medicines as $medicine) {
                            if ($matcher->matches($search, $medicine->search_text, $substring)) {
                                $ids[] = (int) $medicine->id;
                            }
                        }
                    }, 'medicines.id', 'id');
            }
            $this->fuzzyIds[$cacheKey] = $ids;
        }

        return Medicine::query()->select('medicines.id')
            ->selectRaw('CASE WHEN '.$literalSql.' THEN 0 ELSE 1 END as match_rank', $literalBindings)
            ->where(fn (Builder $query): Builder => $query->whereRaw($literalSql, $literalBindings)
                ->orWhereIntegerInRaw('medicines.id', $this->fuzzyIds[$cacheKey]));
    }

    /** @return array{string, list<string>} */
    private function literalCondition(string $search, bool $substring): array
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return ["medicines.search_text ILIKE ? ESCAPE '\\'", [($substring ? '%' : '').addcslashes($search, '\\%_').'%']];
        }

        return ['instr(medicines.search_text, ?) '.($substring ? '> 0' : '= 1'), [$search]];
    }

    private function discountPercentExpression(): string
    {
        return 'CASE WHEN COALESCE(pharmacy_supplier_discounts.supplier_discount_percent, 0.00) > discount_suppliers.default_discount_percent THEN pharmacy_supplier_discounts.supplier_discount_percent ELSE discount_suppliers.default_discount_percent END';
    }

    private function effectivePriceExpression(bool $hasPharmacyContext): string
    {
        if (! $hasPharmacyContext) {
            return 'offers.price';
        }

        return 'CAST(FLOOR(ROUND(offers.price * 100) * (10000 - ROUND(('.$this->discountPercentExpression().') * 100)) / 10000.00) / 100.00 AS NUMERIC)';
    }
}
