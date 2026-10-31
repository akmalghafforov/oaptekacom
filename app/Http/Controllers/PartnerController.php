<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Services\CartMaintenance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PartnerController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['name' => ['nullable', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:255']]);
        $cities = Organization::query()->where('type', OrganizationType::Wholesaler)->where('status', 'active')->where('price_list_hidden', false)->whereNotNull('city')->where('city', '!=', '')->distinct()->orderBy('city')->pluck('city');
        $suppliers = Organization::query()
            ->where('type', OrganizationType::Wholesaler)->where('status', 'active')->where('price_list_hidden', false)
            ->when($request->filled('name'), fn ($query) => $query->where('name', 'like', '%'.$request->string('name')->toString().'%'))
            ->when($request->filled('city'), fn ($query) => $query->where('city', $request->string('city')->toString()))
            ->with(['senderAddresses' => fn ($query) => $query->orderBy('id')])
            ->with('activePriceListImport')
            ->withExists(['offers as has_available_catalog' => fn ($query) => $query->currentAvailable()])
            ->orderByRaw('case when active_price_list_import_id is null then 1 else 0 end')
            ->orderByDesc(
                PriceListImport::query()
                    ->selectRaw("coalesce(activated_at, '1970-01-01 00:00:00')")
                    ->whereColumn('price_list_imports.id', 'organizations.active_price_list_import_id')
            )
            ->paginate(20)
            ->withQueryString();
        $discounts = $request->user()->activeOrganization()->pharmacySupplierDiscounts()->whereIn('supplier_organization_id', $suppliers->pluck('id'))->get()->keyBy('supplier_organization_id');

        return view('partners.index', compact('cities', 'suppliers', 'discounts'));
    }

    public function updateDiscount(Request $request, Organization $supplier, CartMaintenance $maintenance): RedirectResponse
    {
        $value = $request->input('supplier_discount_percent');
        if (is_string($value) && trim($value) === '') {
            $request->merge(['supplier_discount_percent' => null]);
        }

        $data = $request->validate(['supplier_discount_percent' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2']]);
        abort_unless($supplier->type === OrganizationType::Wholesaler && $supplier->status === 'active', 404);
        $supplierDiscountPercent = $data['supplier_discount_percent'] ?? null;
        $maintenance->updateAgreement($request->user(), $supplier, $supplierDiscountPercent === null ? null : (string) $supplierDiscountPercent);

        return back()->with('success', $supplierDiscountPercent === null ? 'Согласованная скидка удалена.' : 'Согласованная скидка сохранена.');
    }
}
