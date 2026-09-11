<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Enums\TradeMode;
use App\Enums\UserRole;
use App\Http\Requests\SendPhoneOtpRequest;
use App\Http\Requests\VerifyPhoneOtpRequest;
use App\Models\ActivationHistory;
use App\Models\Organization;
use App\Models\User;
use App\Services\PhoneOtpService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function loginForm()
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);
        $key = 'staff-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Слишком много попыток.']);
        }
        $user = User::where('email', $data['email'])->first();
        if (! $user || $user->isCustomer() || ! Auth::attempt(['email' => $data['email'], 'password' => $data['password']], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'Неверные данные.']);
        }
        $request->session()->regenerate();
        RateLimiter::clear($key);

        return $request->user()->isAdmin() ? redirect()->route('two-factor.enroll') : redirect()->intended(route('dashboard'));
    }

    public function sendLoginOtp(SendPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        $phone = $request->validated('phone');
        if (! $this->canSend($request, $phone)) {
            return back()->withErrors(['phone' => 'Попробуйте отправить код позже.']);
        }
        $user = $this->phoneLoginUser($phone);
        if ($user && ! $user->is_blocked && ! $this->usesDevelopmentOtp()) {
            $otpService->send('login', $phone);
        }
        $request->session()->put('phone_otp.login', $phone);

        return redirect()->route('login.otp.form')->with('success', 'Если номер доступен для входа, код отправлен.');
    }

    public function loginOtpForm()
    {
        abort_unless(session()->has('phone_otp.login'), 404);

        return view('auth.phone-otp', ['title' => 'Вход по телефону', 'route' => 'login.otp.verify', 'resend_route' => 'login.otp.resend', 'phone' => session('phone_otp.login')]);
    }

    public function verifyLoginOtp(VerifyPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        $phone = $request->validated('phone');
        $usesDevelopmentCode = $this->usesDevelopmentOtp() && hash_equals((string) config('auth.development_otp_code'), $request->validated('code'));
        if ($phone !== $request->session()->get('phone_otp.login') || (! $usesDevelopmentCode && ! $otpService->consume('login', $phone, $request->validated('code')))) {
            return back()->withErrors(['code' => 'Код недействителен или истёк.']);
        }
        $user = $this->phoneLoginUser($phone, false);
        if (! $user) {
            return back()->withErrors(['code' => 'Код недействителен или истёк.']);
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('phone_otp.login');

        return redirect()->intended(route('dashboard'));
    }

    public function registerForm()
    {
        return view('auth.register');
    }

    public function register(Request $request, PhoneOtpService $otpService): RedirectResponse
    {
        $data = $request->validate(['pharmacy_name' => ['required', 'string', 'max:255'], 'phone' => ['required', 'string', 'max:30']]);
        $phone = PhoneNormalizer::normalize($data['phone']);
        if (! $phone) {
            return back()->withErrors(['phone' => 'Введите номер Таджикистана в формате +992XXXXXXXXX.'])->withInput();
        }
        if (User::where('phone', $phone)->exists()) {
            return back()->withErrors(['phone' => 'Этот номер уже используется.'])->withInput();
        }
        if (! $this->canSend($request, $phone) || ! $otpService->send('registration', $phone)) {
            return back()->withErrors(['phone' => 'Не удалось отправить код. Попробуйте позже.'])->withInput();
        }
        $request->session()->put('phone_otp.registration', ['phone' => $phone, 'pharmacy_name' => $data['pharmacy_name']]);

        return redirect()->route('register.otp.form');
    }

    public function registerOtpForm()
    {
        abort_unless(session()->has('phone_otp.registration'), 404);

        return view('auth.phone-otp', ['title' => 'Подтвердите телефон', 'route' => 'register.otp.verify', 'resend_route' => 'register.otp.resend', 'phone' => session('phone_otp.registration.phone')]);
    }

    public function verifyRegistrationOtp(VerifyPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        $registration = $request->session()->get('phone_otp.registration');
        $phone = $request->validated('phone');
        if (! $registration || $phone !== $registration['phone'] || ! $otpService->consume('registration', $phone, $request->validated('code'))) {
            return back()->withErrors(['code' => 'Код недействителен или истёк.']);
        }
        if (User::where('phone', $phone)->exists()) {
            return back()->withErrors(['phone' => 'Этот номер уже используется.']);
        }
        $organization = Organization::create(['name' => $registration['pharmacy_name'], 'phone' => $phone, 'type' => OrganizationType::Pharmacy, 'status' => 'pending']);
        $user = new User(['name' => $registration['pharmacy_name'], 'phone' => $phone]);
        $user->forceFill(['organization_id' => $organization->id, 'role' => UserRole::Pharmacy, 'active_trade_mode' => TradeMode::Buyer])->save();
        ActivationHistory::firstOrCreate(['phone' => $phone], ['organization_id' => $organization->id]);
        $request->session()->forget('phone_otp.registration');

        return redirect()->route('login')->with('success', 'Заявка принята. После проверки администратора будет включён демо-доступ.');
    }

    public function resend(Request $request, PhoneOtpService $otpService, string $purpose): RedirectResponse
    {
        $state = $request->session()->get("phone_otp.{$purpose}");
        $phone = is_array($state) ? $state['phone'] : $state;
        if (! $phone || ! $this->canSend($request, $phone) || ! $otpService->send($purpose === 'login' ? 'login' : 'registration', $phone)) {
            return back()->withErrors(['phone' => 'Не удалось отправить код. Попробуйте позже.']);
        }

        return back()->with('success', 'Новый код отправлен.');
    }

    private function canSend(Request $request, string $phone): bool
    {
        foreach ([['resend', 1, 60], ['quarter-hour', 5, 900], ['day', 10, 86400]] as [$period, $max, $seconds]) {
            foreach (['phone:'.$phone, 'ip:'.$request->ip()] as $identity) {
                if (RateLimiter::tooManyAttempts("otp-send:{$period}:{$identity}", $max)) {
                    return false;
                }
            }
        }
        foreach ([['resend', 60], ['quarter-hour', 900], ['day', 86400]] as [$period, $seconds]) {
            foreach (['phone:'.$phone, 'ip:'.$request->ip()] as $identity) {
                RateLimiter::hit("otp-send:{$period}:{$identity}", $seconds);
            }
        }

        return true;
    }

    private function usesDevelopmentOtp(): bool
    {
        return app()->environment(['local', 'testing']) && filled(config('auth.development_otp_code'));
    }

    private function phoneLoginUser(string $phone, bool $includeBlocked = true): ?User
    {
        $query = User::where('phone', $phone);
        if (! $this->usesDevelopmentOtp()) {
            $query->where('role', UserRole::Pharmacy);
        }
        if (! $includeBlocked) {
            $query->where('is_blocked', false);
        }

        return $query->first();
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
