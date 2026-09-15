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
        return $this->offersQuery($filters, true)
            ->with(['medicine', 'organization'])
            ->orderBy('offers.price')
            ->orderBy('offers.id')
            ->cursorPaginate(50, ['offers.*'], 'cursor', $filters['cursor'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{id: int, label: string, count: int}>
     */
    public function facets(array $filters): Collection
    {
        return ProductCategory::query()
            ->select(['product_categories.id', 'product_categories.label', 'product_categories.sort_order'])
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
            ->groupBy(['product_categories.id', 'product_categories.label', 'product_categories.sort_order'])
            ->orderByDesc('offer_count')
            ->orderBy('product_categories.sort_order')
            ->orderBy('product_categories.id')
            ->get()
            ->map(fn (ProductCategory $category): array => [
                'id' => $category->id,
                'label' => $category->label,
                'count' => (int) $category->getAttribute('offer_count'),
            ]);
    }

    /** @param array<string, mixed> $filters */
    private function offersQuery(array $filters, bool $includeCategory): Builder
    {
        return Offer::query()
            ->currentCatalog()
            ->whereHas('medicine', function (Builder $query) use ($filters): void {
                $search = mb_strtolower($filters['q']);

                if (DB::connection()->getDriverName() === 'pgsql') {
                    $escaped = addcslashes($search, '\\%_');
                    $query->whereRaw("medicines.search_text ILIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
                } else {
                    $query->whereRaw('instr(medicines.search_text, ?) > 0', [$search]);
                }

                if (! empty($filters['form'])) {
                    $query->where('medicines.form', $filters['form']);
                }
            })
            ->when(! empty($filters['city']), fn (Builder $query): Builder => $query->whereHas(
                'organization',
                fn (Builder $organizationQuery): Builder => $organizationQuery->where('city', $filters['city']),
            ))
            ->when(! empty($filters['supplier']), fn (Builder $query): Builder => $query->where('offers.organization_id', $filters['supplier']))
            ->when(isset($filters['min_price']), fn (Builder $query): Builder => $query->where('offers.price', '>=', $filters['min_price']))
            ->when(isset($filters['max_price']), fn (Builder $query): Builder => $query->where('offers.price', '<=', $filters['max_price']))
            ->when($includeCategory && ! empty($filters['category']), fn (Builder $query): Builder => $query->whereHas(
                'medicine.categories',
                fn (Builder $categoryQuery): Builder => $categoryQuery->whereKey($filters['category']),
            ));
    }
}
