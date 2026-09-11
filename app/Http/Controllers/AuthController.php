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
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function loginForm(): View
    {
        return view('auth.login');
    }

    public function adminLoginForm(): View
    {
        return view('auth.admin-login');
    }

    public function providerLoginForm(): View
    {
        return view('auth.provider-login');
    }

    public function adminLogin(Request $request): RedirectResponse
    {
        return $this->loginAsRole($request, UserRole::Admin);
    }

    public function providerLogin(Request $request): RedirectResponse
    {
        return $this->loginAsRole($request, UserRole::Wholesaler);
    }

    private function loginAsRole(Request $request, UserRole $role): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required']]);
        $key = 'staff-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Слишком много попыток.']);
        }
        $user = User::where('email', $data['email'])->where('role', $role)->first();
        if (! $user || ! Auth::attempt(['email' => $data['email'], 'password' => $data['password'], 'role' => $role], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'Неверные данные.']);
        }
        $request->session()->regenerate();
        RateLimiter::clear($key);

        return $role === UserRole::Admin ? redirect()->route('two-factor.enroll') : redirect()->intended(route('dashboard'));
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
        if ($this->activeSessionsFor($user)->isNotEmpty()) {
            $request->session()->put('phone_otp.pending_login_user_id', $user->id);
            $request->session()->forget('phone_otp.login');

            return redirect()->route('login.session.confirmation');
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('phone_otp.login');

        return redirect()->intended(route('dashboard'));
    }

    public function sessionConfirmationForm(Request $request): View
    {
        $user = $this->pendingPharmacyUser($request);
        $session = $this->activeSessionsFor($user)->first();

        abort_unless($session, 404);

        return view('auth.session-confirmation', [
            'session' => $session,
            'lastActivity' => now()->setTimestamp($session->last_activity)->format('d.m.Y H:i'),
        ]);
    }

    public function confirmSessionReplacement(Request $request): RedirectResponse
    {
        $user = $this->pendingPharmacyUser($request);

        $this->sessionQuery()->where('user_id', $user->id)->delete();
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(['phone_otp.login', 'phone_otp.pending_login_user_id']);

        return redirect()->intended(route('dashboard'));
    }

    public function cancelSessionReplacement(Request $request): RedirectResponse
    {
        $this->pendingPharmacyUser($request);
        $request->session()->forget(['phone_otp.login', 'phone_otp.pending_login_user_id']);

        return redirect()->route('login')->with('warning', 'Вход отменён. Активный сеанс на другом устройстве сохранён.');
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
        if (app()->environment(['local', 'testing']) ) {
            return true;
        }

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
        $query->where('role', UserRole::Pharmacy);
        if (! $includeBlocked) {
            $query->where('is_blocked', false);
        }

        return $query->first();
    }

    private function pendingPharmacyUser(Request $request): User
    {
        $userId = $request->session()->get('phone_otp.pending_login_user_id');
        $user = is_numeric($userId)
            ? User::whereKey($userId)->where('role', UserRole::Pharmacy)->first()
            : null;

        abort_unless($user, 404);

        return $user;
    }

    private function activeSessionsFor(User $user): Collection
    {
        return $this->sessionQuery()
            ->where('user_id', $user->id)
            ->where('last_activity', '>=', now()->subMinutes(config('session.lifetime'))->getTimestamp())
            ->orderByDesc('last_activity')
            ->get();
    }

    private function sessionQuery(): Builder
    {
        return DB::connection(config('session.connection'))->table(config('session.table'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
