<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\PharmacySupplier;
use App\Models\SupplierInvitation;
use App\Services\AuditLogger;
use App\Support\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->with(['priceListImports' => fn ($query) => $query->orderByRaw('COALESCE(received_at, created_at) DESC')->orderByDesc('id')->limit(1)])
            ->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();
        $links = $request->user()->organization->pharmacySuppliers()->whereIn('supplier_organization_id', $suppliers->pluck('id'))->get()->keyBy('supplier_organization_id');

        return view('partners.index', compact('cities', 'suppliers', 'links'));
    }

    public function linkPhone(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $request->validate(['phone' => ['required', 'string']]);
        $phone = PhoneNormalizer::normalize($request->string('phone')->toString());
        if ($phone === null) {
            return back()->withErrors(['phone' => 'Введите действительный номер телефона.']);
        }
        $supplier = Organization::query()->where('type', OrganizationType::Wholesaler)->where('status', 'active')->where('phone', $phone)->first();
        if (! $supplier) {
            return back()->withErrors(['phone' => 'Активный поставщик с таким телефоном не найден.']);
        }
        $this->link($request, $supplier, $auditLogger);

        return back()->with('success', 'Поставщик добавлен.');
    }

    public function redeem(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:100']]);
        $hash = hash('sha256', strtoupper(trim($request->string('code')->toString())));
        $linked = DB::transaction(function () use ($request, $auditLogger, $hash): bool {
            $invitation = SupplierInvitation::query()->where('code_hash', $hash)->lockForUpdate()->first();
            if (! $invitation || $invitation->redeemed_at || $invitation->revoked_at || $invitation->expires_at->isPast()) {
                return false;
            }
            $supplier = Organization::query()->whereKey($invitation->supplier_organization_id)->where('type', OrganizationType::Wholesaler)->where('status', 'active')->first();
            if (! $supplier) {
                return false;
            }
            $this->link($request, $supplier, $auditLogger);
            $invitation->update(['redeemed_at' => now(), 'redeemed_by_pharmacy_id' => $request->user()->organization_id]);
            $auditLogger->log('supplier_invitation.redeemed', $invitation);

            return true;
        });

        return $linked ? back()->with('success', 'Поставщик добавлен.') : back()->withErrors(['code' => 'Код недействителен или истёк.']);
    }

    public function updateDiscount(Request $request, Organization $supplier, AuditLogger $auditLogger): RedirectResponse
    {
        $data = $request->validate(['discount_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2']]);
        $link = $request->user()->organization->pharmacySuppliers()->where('supplier_organization_id', $supplier->id)->firstOrFail();
        $before = $link->only('discount_percent');
        $link->update(['discount_percent' => $data['discount_percent']]);
        $auditLogger->log('pharmacy_supplier.discount_updated', $link, $before, $link->fresh()->only('discount_percent'));

        return back()->with('success', 'Согласованная скидка сохранена.');
    }

    private function link(Request $request, Organization $supplier, AuditLogger $auditLogger): void
    {
        $link = PharmacySupplier::query()->firstOrCreate([
            'pharmacy_organization_id' => $request->user()->organization_id,
            'supplier_organization_id' => $supplier->id,
        ]);
        if ($link->wasRecentlyCreated) {
            $auditLogger->log('pharmacy_supplier.linked', $link);
        }
    }
}
