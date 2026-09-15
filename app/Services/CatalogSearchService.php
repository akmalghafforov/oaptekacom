<?php

namespace App\Services;

use App\Models\Offer;
use App\Models\ProductCategory;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogSearchService
{
    /** @param array<string, mixed> $filters */
    public function search(array $filters): CursorPaginator
    {
        $query = $this->offersQuery($filters, true)
            ->with(['medicine.categories', 'organization', 'import']);

        match ($filters['sort'] ?? 'price_asc') {
            'price_desc' => $query->orderByDesc('offers.price')->orderByDesc('offers.id'),
            'updated_desc' => $query->orderByDesc('offers.updated_at')->orderByDesc('offers.id'),
            'name_asc' => $query->join('medicines as sort_medicines', 'sort_medicines.id', '=', 'offers.medicine_id')->orderBy('sort_medicines.normalized_name')->orderBy('offers.id'),
            default => $query->orderBy('offers.price')->orderBy('offers.id'),
        };

        return $query->cursorPaginate((int) config('catalog.page_size'), ['offers.*'], 'cursor', $filters['cursor'] ?? null);
    }

    /** @param array<string, mixed> $filters */
    public function total(array $filters): int
    {
        return $this->offersQuery($filters, true)->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{id: int, code: string, label: string, count: int, icon: string}>
     */
    public function facets(array $filters): Collection
    {
        return ProductCategory::query()
            ->select(['product_categories.id', 'product_categories.code', 'product_categories.label', 'product_categories.sort_order'])
            ->selectRaw('count(offers.id) as offer_count')
            ->join('medicine_product_category', 'medicine_product_category.product_category_id', '=', 'product_categories.id')
            ->joinSub(
                $this->offersQuery($filters, false)->select(['offers.id', 'offers.medicine_id']),
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
    public function cities(array $filters = []): Collection
    {
        return DB::query()->fromSub($this->offersQuery($filters, false)->select('offers.organization_id'), 'matching_offers')
            ->join('organizations', 'organizations.id', '=', 'matching_offers.organization_id')
            ->whereNotNull('organizations.city')->where('organizations.city', '<>', '')
            ->distinct()->orderBy('organizations.city')->pluck('organizations.city')
            ->map(fn (string $city): array => ['value' => $city, 'label' => $city]);
    }

    /** @return Collection<int, array{id: int, name: string, city: ?string}> */
    public function suppliers(array $filters = []): Collection
    {
        return DB::query()->fromSub($this->offersQuery($filters, false)->select('offers.organization_id'), 'matching_offers')
            ->join('organizations', 'organizations.id', '=', 'matching_offers.organization_id')
            ->distinct()->orderBy('organizations.name')->get(['organizations.id', 'organizations.name', 'organizations.city'])
            ->map(fn (object $supplier): array => ['id' => (int) $supplier->id, 'name' => $supplier->name, 'city' => $supplier->city]);
    }

    /** @param array<string, mixed> $filters */
    private function offersQuery(array $filters, bool $includeCategory): Builder
    {
        return Offer::query()
            ->currentCatalog()
            ->whereHas('medicine', function (Builder $query) use ($filters): void {
                $search = mb_strtolower($filters['q'] ?? '');

                if ($search === '') {
                    return;
                } elseif (DB::connection()->getDriverName() === 'pgsql') {
                    $escaped = addcslashes($search, '\\%_');
                    $query->whereRaw("medicines.search_text ILIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
                } else {
                    $query->whereRaw('instr(medicines.search_text, ?) > 0', [$search]);
                }

                if (! empty($filters['form'])) {
                    $query->where('medicines.form', $filters['form']);
                }
            })
            ->when(! empty($filters['cities']), fn (Builder $query): Builder => $query->whereHas(
                'organization',
                fn (Builder $organizationQuery): Builder => $organizationQuery->whereIn('city', $filters['cities']),
            ))
            ->when(! empty($filters['suppliers']), fn (Builder $query): Builder => $query->whereIn('offers.organization_id', $filters['suppliers']))
            ->when(isset($filters['min_price']), fn (Builder $query): Builder => $query->where('offers.price', '>=', $filters['min_price']))
            ->when(isset($filters['max_price']), fn (Builder $query): Builder => $query->where('offers.price', '<=', $filters['max_price']))
            ->when($includeCategory && ! empty($filters['category']), fn (Builder $query): Builder => $query->whereHas(
                'medicine.categories',
                fn (Builder $categoryQuery): Builder => $categoryQuery->whereKey($filters['category']),
            ));
    }
}
