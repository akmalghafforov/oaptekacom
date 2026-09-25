<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\PharmacySupplierDiscount;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PartnerController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['name' => ['nullable', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:255']]);
        $cities = Organization::query()->where('type', OrganizationType::Wholesaler)->where('status', 'active')->whereNotNull('city')->where('city', '!=', '')->distinct()->orderBy('city')->pluck('city');
        $suppliers = Organization::query()
            ->where('type', OrganizationType::Wholesaler)->where('status', 'active')
            ->when($request->filled('name'), fn ($query) => $query->where('name', 'like', '%'.$request->string('name')->toString().'%'))
            ->when($request->filled('city'), fn ($query) => $query->where('city', $request->string('city')->toString()))
            ->with(['senderAddresses' => fn ($query) => $query->orderBy('id')])
            ->with('activePriceListImport')
            ->withExists(['offers as has_available_catalog' => fn ($query) => $query->currentAvailable()])
            ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();
        $discounts = $request->user()->organization->pharmacySupplierDiscounts()->whereIn('supplier_organization_id', $suppliers->pluck('id'))->get()->keyBy('supplier_organization_id');

        return view('partners.index', compact('cities', 'suppliers', 'discounts'));
    }

    public function updateDiscount(Request $request, Organization $supplier, AuditLogger $auditLogger): RedirectResponse
    {
        $value = $request->input('supplier_discount_percent');
        if (is_string($value) && trim($value) === '') {
            $request->merge(['supplier_discount_percent' => null]);
        }

        $data = $request->validate(['supplier_discount_percent' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2']]);
        abort_unless($supplier->type === OrganizationType::Wholesaler && $supplier->status === 'active', 404);
        $discount = $request->user()->organization->pharmacySupplierDiscounts()->where('supplier_organization_id', $supplier->id)->first();
        $supplierDiscountPercent = $data['supplier_discount_percent'] ?? null;

        if ($supplierDiscountPercent === null) {
            if ($discount) {
                $before = $discount->only('supplier_discount_percent');
                $discount->delete();
                $auditLogger->log('pharmacy_supplier_discount.cleared', $discount, $before);
            }

            return back()->with('success', 'Согласованная скидка удалена.');
        }

        if ($discount) {
            $before = $discount->only('supplier_discount_percent');
            $discount->update(['supplier_discount_percent' => $supplierDiscountPercent]);
            $auditLogger->log('pharmacy_supplier_discount.updated', $discount, $before, $discount->fresh()->only('supplier_discount_percent'));
        } else {
            $discount = PharmacySupplierDiscount::query()->create([
                'pharmacy_organization_id' => $request->user()->organization_id,
                'supplier_organization_id' => $supplier->id,
                'supplier_discount_percent' => $supplierDiscountPercent,
            ]);
            $auditLogger->log('pharmacy_supplier_discount.created', $discount, [], $discount->only('supplier_discount_percent'));
        }

        return back()->with('success', 'Согласованная скидка сохранена.');
    }
}
