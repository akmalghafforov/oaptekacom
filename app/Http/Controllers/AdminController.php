<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Enums\TradeMode;
use App\Enums\UserRole;
use App\Http\Requests\ProvisionWholesalerRequest;
use App\Models\ActivationHistory;
use App\Models\ModuleSetting;
use App\Models\Organization;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function index()
    {
        return view('admin.index', ['pending' => Organization::where('status', 'pending')->get(), 'payments' => PaymentRequest::with('organization')->where('status', 'pending')->get(), 'expiring' => Organization::where('subscription_until', '<', now()->addDays(7))->where('status', 'active')->get()]);
    }

    public function approve(Organization $organization)
    {
        abort_unless($organization->status === 'pending', 422);
        $before = $organization->only('status', 'subscription_until');
        $history = ActivationHistory::where('phone', $organization->phone)->first();
        $until = $history?->demo_used_at?->copy()->addDay();
        if (! $history?->demo_used_at) {
            $history?->update(['demo_used_at' => now()]);
            $until = now()->addDay();
        } $organization->update(['status' => 'active', 'subscription_until' => $until]);
        $organization->users()->update(['approved_at' => now()]);
        app(AuditLogger::class)->log('organization.approved', $organization, $before, $organization->only('status', 'subscription_until'));

        return back()->with('success', 'Организация одобрена.');
    }

    public function payment(PaymentRequest $payment, Request $request)
    {
        $data = $request->validate(['action' => 'required|in:approve,reject', 'note' => 'nullable|string|max:1000']);
        $before = $payment->only('status', 'admin_note');
        $payment->update(['status' => $data['action'] === 'approve' ? 'approved' : 'rejected', 'admin_note' => $data['note'] ?? null, 'reviewed_by' => $request->user()->id]);
        if ($data['action'] === 'approve') {
            $organization = $payment->organization;
            $organization->update(['subscription_until' => max(now(), $organization->subscription_until ?? now())->addDays($payment->days), 'status' => 'active']);
        } app(AuditLogger::class)->log('payment.reviewed', $payment, $before, $payment->only('status', 'admin_note'));

        return back()->with('success', 'Запрос обработан.');
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
