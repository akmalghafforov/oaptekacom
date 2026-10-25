<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Enums\SubscriptionPlan;
use App\Enums\TradeMode;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(): View
    {
        return view('admin.index', [
            'pending' => Organization::query()
                ->where('status', 'pending')
                ->with(['assignedUsers', 'sourceSupplierOrganization'])
                ->get(),
        ]);
    }

    public function approve(Organization $organization, AuditLogger $auditLogger): RedirectResponse
    {
        $organization = DB::transaction(function () use ($organization, $auditLogger): Organization {
            $pendingOrganization = Organization::query()
                ->lockForUpdate()
                ->findOrFail($organization->id);
            if ($pendingOrganization->status !== 'pending') {
                throw ValidationException::withMessages(['organization' => 'Организация больше не ожидает одобрения. Обновите страницу.']);
            }
            $isPharmacy = $pendingOrganization->type === OrganizationType::Pharmacy;
            if (! $isPharmacy && $pendingOrganization->setup_required) {
                throw ValidationException::withMessages(['organization' => 'Владелец должен сохранить данные организации в профиле рабочего режима.']);
            }

            $organizationUser = $pendingOrganization->assignedUsers()
                ->when(! $isPharmacy, fn ($query) => $query->where(fn ($query) => $query->whereNotNull('phone_verified_at')->orWhere('users.role', UserRole::Admin)))
                ->lockForUpdate()
                ->first();
            $sourceSupplier = $pendingOrganization->sourceSupplierOrganization()->lockForUpdate()->first();
            if (! $isPharmacy && ! $organizationUser) {
                throw ValidationException::withMessages(['organization' => 'Для одобрения организации необходимо подтверждение телефона по SMS.']);
            }
            if (! $isPharmacy && $sourceSupplier && $sourceSupplier->status !== 'active') {
                throw ValidationException::withMessages(['organization' => 'Сначала одобрите связанного поставщика: '.$sourceSupplier->name.'.']);
            }

            $before = $pendingOrganization->only('status');
            $pendingOrganization->update($isPharmacy ? ['status' => 'active', 'setup_required' => false] : ['status' => 'active']);
            $organizationUser?->forceFill(array_filter([
                'approved_at' => now(),
                'subscription_plan' => $organizationUser->isCustomer() ? SubscriptionPlan::Free : null,
            ], fn (mixed $value): bool => $value !== null))->save();
            $auditLogger->log('organization.approved', $pendingOrganization, $before, $pendingOrganization->only('status'));

            return $pendingOrganization;
        });

        return back()->with('success', 'Организация одобрена.');
    }

    public function storeSupplier(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:32', 'unique:organizations,phone']]);
        $supplier = Organization::create([...$validated, 'type' => OrganizationType::Wholesaler, 'status' => 'active', 'supplier_mode' => TradeMode::Supplier->value]);
        $auditLogger->log('organization.supplier_created', $supplier, [], $supplier->only(['name', 'city', 'phone', 'type', 'status']));

        return redirect()->route('admin.supplier-import-profiles.edit', $supplier)->with('success', 'Поставщик создан. Теперь настройте профиль импорта.');
    }

    public function users(): View
    {
        return view('admin.users', ['users' => User::with('organization')->paginate(30)]);
    }

    public function toggleBlock(User $user, AuditLogger $auditLogger): RedirectResponse
    {
        abort_if($user->isAdmin(), 422);
        $before = $user->only('is_blocked');
        $user->forceFill(['is_blocked' => ! $user->is_blocked])->save();
        $auditLogger->log('user.block_toggled', $user, $before, $user->only('is_blocked'));

        return back();
    }

    public function remediatePhone(User $user, Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($user->isCustomer() || $user->isWholesaler(), 422);
        $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $phone = PhoneNormalizer::normalize($request->string('phone')->toString());
        if (! $phone) {
            return back()->withErrors(['phone' => 'Введите номер Таджикистана в формате +992XXXXXXXXX.']);
        }
        if (User::where('phone', $phone)->where('role', $user->role)->whereKeyNot($user->id)->exists()) {
            return back()->withErrors(['phone' => 'Этот номер уже используется.']);
        }
        $before = $user->only('phone', 'is_blocked');
        $user->forceFill(['phone' => $phone, 'is_blocked' => false, 'password' => null, 'password_change_required' => false])->save();
        $user->organization?->update(['phone' => $phone]);
        $auditLogger->log('user.phone_remediated', $user, $before, $user->only('phone', 'is_blocked'));

        return back()->with('success', 'Телефон подтверждён администратором, доступ разблокирован.');
    }
}
