<?php

namespace App\Http\Controllers;

use App\Models\Offer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $offers = Offer::query()
            ->with(['medicine', 'organization'])
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('quantity')->orWhere('quantity', '>', 0))
            ->where(fn (Builder $query): Builder => $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', now('Asia/Dushanbe')->toDateString()))
            ->whereHas('organization', fn (Builder $query): Builder => $query->whereColumn('organizations.active_price_list_import_id', 'offers.price_list_import_id'))
            ->when(mb_strlen($search) >= 2, function (Builder $query) use ($search): void {
                $query->whereHas('medicine', fn (Builder $medicineQuery): Builder => $medicineQuery->where('search_text', 'ilike', '%'.$search.'%'));
            })
            ->when($request->city, fn (Builder $query, string $city): Builder => $query->whereHas('organization', fn (Builder $organizationQuery): Builder => $organizationQuery->where('city', $city)))
            ->when($request->supplier, fn (Builder $query, string $supplier): Builder => $query->where('organization_id', $supplier))
            ->when($request->form, fn (Builder $query, string $form): Builder => $query->whereHas('medicine', fn (Builder $medicineQuery): Builder => $medicineQuery->where('form', $form)))
            ->when($request->min_price, fn (Builder $query, string $minimumPrice): Builder => $query->where('price', '>=', $minimumPrice))
            ->when($request->max_price, fn (Builder $query, string $maximumPrice): Builder => $query->where('price', '<=', $maximumPrice))
            ->orderBy('price')
            ->paginate(20)
            ->withQueryString();

        return view('catalog.index', ['offers' => $offers, 'q' => $search]);
    }
}
