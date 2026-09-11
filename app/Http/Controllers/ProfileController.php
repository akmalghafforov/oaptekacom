<?php

namespace App\Http\Controllers;

use App\Enums\TradeMode;
use App\Http\Requests\SendPhoneOtpRequest;
use App\Http\Requests\UpdateOrganizationRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\VerifyPhoneOtpRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PhoneOtpService;
use Illuminate\Http\RedirectResponse;

class ProfileController extends Controller
{
    public function edit()
    {
        return view('profile.edit', ['user' => request()->user(), 'organization' => request()->user()->organization]);
    }

    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $before = $user->only('name', 'email', 'phone', 'theme');
        $data = $request->safe()->only($user->isCustomer() ? ['name', 'theme'] : ['name', 'email', 'phone', 'theme']);
        if (! $user->isCustomer() && $request->filled('password')) {
            $data['password'] = $request->string('password')->toString();
            $data['password_change_required'] = false;
        }
        $user->forceFill($data)->save();
        app(AuditLogger::class)->log('profile.updated', $user, $before, $user->only('name', 'email', 'phone', 'theme'));

        return back()->with('success', 'Профиль сохранён.');
    }

    public function sendPhoneChange(SendPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        abort_unless($request->user()->isCustomer(), 403);
        $phone = $request->validated('phone');
        if (User::where('phone', $phone)->whereKeyNot($request->user()->id)->exists()) {
            return back()->withErrors(['phone' => 'Этот номер уже используется.']);
        }
        if (! $otpService->send('phone_change', $phone)) {
            return back()->withErrors(['phone' => 'Не удалось отправить код. Попробуйте позже.']);
        }
        $request->session()->put('phone_otp.change_phone', $phone);

        return redirect()->route('profile.phone.verify');
    }

    public function phoneChangeForm()
    {
        abort_unless(request()->user()->isCustomer() && session()->has('phone_otp.change_phone'), 404);

        return view('auth.phone-otp', ['title' => 'Подтвердите новый телефон', 'route' => 'profile.phone.confirm', 'phone' => session('phone_otp.change_phone'), 'resend_route' => 'profile.phone.send']);
    }

    public function confirmPhoneChange(VerifyPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        abort_unless($request->user()->isCustomer(), 403);
        $phone = $request->validated('phone');
        if ($phone !== $request->session()->get('phone_otp.change_phone') || ! $otpService->consume('phone_change', $phone, $request->validated('code'))) {
            return back()->withErrors(['code' => 'Код недействителен или истёк.']);
        }
        if (User::where('phone', $phone)->whereKeyNot($request->user()->id)->exists()) {
            return back()->withErrors(['phone' => 'Этот номер уже используется.']);
        }
        $user = $request->user();
        $user->update(['phone' => $phone]);
        $user->organization?->update(['phone' => $phone]);
        $request->session()->forget('phone_otp.change_phone');

        return redirect()->route('profile.edit')->with('success', 'Телефон подтверждён и сохранён.');
    }

    public function updateOrganization(UpdateOrganizationRequest $request)
    {
        $user = $request->user();
        $organization = $user->organization;
        abort_unless($organization, 403);
        $before = $organization->only('name', 'city', 'phone', 'minimum_order', 'delivery_conditions');
        $organization->update($request->safe()->only(['name', 'city', 'phone', 'minimum_order', 'delivery_conditions']));
        if ($user->isWholesaler() && $request->filled('active_trade_mode')) {
            $mode = TradeMode::from($request->string('active_trade_mode')->toString());
            abort_unless($organization->permitsMode($mode), 422);
            $user->update(['active_trade_mode' => $mode]);
        }
        app(AuditLogger::class)->log('organization.updated', $organization, $before, $organization->fresh()->only('name', 'city', 'phone', 'minimum_order', 'delivery_conditions'));

        return back()->with('success', 'Настройки организации сохранены.');
    }
}
