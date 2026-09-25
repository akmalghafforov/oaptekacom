<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogSearchRequest;
use App\Models\CartItem;
use App\Models\Organization;
use App\Models\ProductCategory;
use App\Services\CatalogSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        $canBuy = $request->user()->canBuy();
        $cartTotal = $canBuy ? (int) CartItem::query()->whereHas('cart', fn ($query) => $query->where('user_id', $request->user()->id))->sum('quantity') : 0;

        return view('catalog.index', [
            'categories' => ProductCategory::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'cities' => Organization::query()->whereNotNull('city')->where('city', '<>', '')->distinct()->orderBy('city')->pluck('city'),
            'suppliers' => Organization::query()->whereNotNull('active_price_list_import_id')->orderBy('name')->get(['id', 'name', 'city']),
            'canBuy' => $canBuy,
            'cartTotal' => $cartTotal,
        ]);
    }

    public function search(CatalogSearchRequest $request, CatalogSearchService $catalogSearch): JsonResponse
    {
        $filters = $request->validated();
        $pharmacyOrganizationId = $request->user()->isCustomer() ? $request->user()->organization_id : null;
        $offers = $catalogSearch->search($filters, $pharmacyOrganizationId);
        $view = $filters['view'] ?? 'list';
        $cartItems = $request->user()->canBuy()
            ? CartItem::query()
                ->whereHas('cart', fn ($query) => $query->where('user_id', $request->user()->id))
                ->whereIn('offer_id', $offers->getCollection()->pluck('id'))
                ->get(['id', 'offer_id', 'quantity'])
                ->keyBy('offer_id')
            : collect();
        $cartQuantities = $cartItems->mapWithKeys(fn (CartItem $item): array => [$item->offer_id => $item->quantity]);
        $cartItemIds = $cartItems->mapWithKeys(fn (CartItem $item): array => [$item->offer_id => $item->id]);
        $cartTotal = $request->user()->canBuy()
            ? (int) CartItem::query()->whereHas('cart', fn ($query) => $query->where('user_id', $request->user()->id))->sum('quantity')
            : 0;
        $viewData = ['offers' => $offers->getCollection(), 'cartQuantities' => $cartQuantities, 'cartItemIds' => $cartItemIds, 'canBuy' => $request->user()->canBuy()];
        $cards = view('catalog.partials.offer-cards', $viewData + ['layout' => $view])->render();
        $rows = $view === 'list' ? view('catalog.partials.offer-table-rows', $viewData)->render() : null;
        $mobileRows = $view === 'list' ? view('catalog.partials.offer-mobile-rows', $viewData)->render() : null;
        $total = $catalogSearch->total($filters, $pharmacyOrganizationId);
        $isFirstPage = empty($filters['cursor']);

        return response()->json([
            'html' => $cards,
            'next_cursor' => $offers->nextCursor()?->encode(),
            'has_more' => $offers->hasMorePages(),
            'fragments' => ['desktop_rows' => $rows, 'mobile_rows' => $mobileRows, 'cards' => $cards, 'supplier_cards' => $view === 'suppliers' ? view('catalog.partials.supplier-cards', $viewData)->render() : null],
            'facets' => $isFirstPage ? (array_key_exists('view', $filters) ? [
                'all_count' => $total,
                'categories' => $catalogSearch->facets($filters, $pharmacyOrganizationId)->values(),
                'cities' => $catalogSearch->cities($filters, $pharmacyOrganizationId)->values(),
                'suppliers' => $catalogSearch->suppliers($filters, $pharmacyOrganizationId)->values(),
            ] : $catalogSearch->facets($filters, $pharmacyOrganizationId)->values()) : null,
            'pagination' => ['next_cursor' => $offers->nextCursor()?->encode(), 'has_more' => $offers->hasMorePages(), 'returned' => $offers->count(), 'total' => $total],
            'cart' => ['total_quantity' => $cartTotal],
            'applied' => ['sort' => $filters['sort'] ?? 'price_asc'],
        ]);
    }
}
