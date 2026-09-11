<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Enums\SubscriptionPlan;
use App\Enums\TradeMode;
use App\Enums\UserRole;
use App\Http\Requests\ProvisionWholesalerRequest;
use App\Models\ModuleSetting;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function index()
    {
        return view('admin.index', [
            'pending' => Organization::query()
                ->where('status', 'pending')
                ->with(['users' => fn ($query) => $query->where('role', UserRole::Pharmacy)])
                ->get(),
        ]);
    }

    public function approve(Organization $organization)
    {
        $organization = DB::transaction(function () use ($organization): Organization {
            $pendingOrganization = Organization::query()
                ->lockForUpdate()
                ->findOrFail($organization->id);
            abort_unless($pendingOrganization->status === 'pending', 422);

            $pharmacyUser = $pendingOrganization->users()
                ->where('role', UserRole::Pharmacy)
                ->whereNotNull('phone_verified_at')
                ->lockForUpdate()
                ->first();
            abort_unless($pharmacyUser, 422);

            $before = $pendingOrganization->only('status');
            $pendingOrganization->update(['status' => 'active']);
            $pharmacyUser->forceFill([
                'approved_at' => now(),
                'subscription_plan' => SubscriptionPlan::Free,
            ])->save();
            app(AuditLogger::class)->log('organization.approved', $pendingOrganization, $before, $pendingOrganization->only('status'));

            return $pendingOrganization;
        });

        return back()->with('success', 'Организация одобрена.');
    }

    public function users()
    {
        return view('admin.users', ['users' => User::with('organization')->paginate(30)]);
    }

    public function toggleBlock(User $user)
    {
        abort_if($user->isAdmin(), 422);
        $before = $user->only('is_blocked');
        $user->forceFill(['is_blocked' => ! $user->is_blocked])->save();
        app(AuditLogger::class)->log('user.block_toggled', $user, $before, $user->only('is_blocked'));

        return back();
    }

    public function remediatePharmacyPhone(User $user, Request $request)
    {
        abort_unless($user->isCustomer(), 422);
        $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $phone = PhoneNormalizer::normalize($request->string('phone')->toString());
        if (! $phone) {
            return back()->withErrors(['phone' => 'Введите номер Таджикистана в формате +992XXXXXXXXX.']);
        }
        if (User::where('phone', $phone)->whereKeyNot($user->id)->exists()) {
            return back()->withErrors(['phone' => 'Этот номер уже используется.']);
        }
        $before = $user->only('phone', 'is_blocked');
        $user->forceFill(['phone' => $phone, 'is_blocked' => false, 'password' => null, 'password_change_required' => false])->save();
        $user->organization?->update(['phone' => $phone]);
        app(AuditLogger::class)->log('pharmacy.phone_remediated', $user, $before, $user->only('phone', 'is_blocked'));

        return back()->with('success', 'Телефон подтверждён администратором, доступ разблокирован.');
    }

    public function modules()
    {
        return view('admin.modules', ['modules' => ModuleSetting::orderBy('key')->get()]);
    }

    public function updateModule(ModuleSetting $module, Request $request)
    {
        $before = $module->only('enabled');
        $module->update(['enabled' => $request->boolean('enabled')]);
        app(AuditLogger::class)->log('module.updated', $module, $before, $module->only('enabled'));

        return back()->with('success', 'Настройка модуля сохранена.');
    }

    public function provisionWholesaler(ProvisionWholesalerRequest $request)
    {
        $data = $request->validated();
        $temporaryPassword = str()->password(16);
        $organization = Organization::create(['name' => $data['organization_name'], 'phone' => $data['phone'], 'type' => OrganizationType::Wholesaler, 'supplier_mode' => $data['supplier_mode'], 'status' => 'active']);
        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'], 'password' => $temporaryPassword]);
        $user->forceFill(['organization_id' => $organization->id, 'role' => UserRole::Wholesaler, 'active_trade_mode' => $organization->permitsMode(TradeMode::Supplier) ? TradeMode::Supplier : TradeMode::Buyer, 'password_change_required' => true])->save();
        app(AuditLogger::class)->log('wholesaler.provisioned', $organization, [], ['name' => $organization->name]);

        return back()->with('success', "Оптовик создан. Временный пароль: {$temporaryPassword}");
    }
}
