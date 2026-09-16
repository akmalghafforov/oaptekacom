<?php

namespace App\Http\Controllers;

use App\Enums\OrganizationType;
use App\Enums\TradeMode;
use App\Enums\UserRole;
use App\Http\Requests\SendPhoneOtpRequest;
use App\Http\Requests\VerifyPhoneOtpRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\PhoneOtpService;
use App\Support\UserAgentFormatter;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function __construct(private readonly UserAgentFormatter $userAgentFormatter) {}

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
        return view('auth.provider-login', ['registration' => false]);
    }

    public function supplierRegisterForm(): View
    {
        return view('auth.provider-login', ['registration' => true]);
    }

    public function adminLogin(Request $request): RedirectResponse
    {
        return $this->loginAsRole($request, UserRole::Admin);
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

        return $role === UserRole::Admin && ! app()->environment('local')
            ? redirect()->route('two-factor.enroll')
            : redirect()->intended(route($user->defaultLandingRouteName()));
    }

    public function sendLoginOtp(SendPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        return $this->sendLoginOtpForRole($request, $otpService, UserRole::Pharmacy);
    }

    public function sendSupplierLoginOtp(SendPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        return $this->sendLoginOtpForRole($request, $otpService, UserRole::Wholesaler);
    }

    private function sendLoginOtpForRole(Request $request, PhoneOtpService $otpService, UserRole $role): RedirectResponse
    {
        $phone = $request->validated('phone');
        if (! $this->phoneLoginUser($phone, true, $role)) {
            $request->session()->put($this->registrationSessionKey($role), ['phone' => $phone]);

            return redirect()->route($this->registrationDetailsRoute($role));
        }

        return $this->sendLoginOtpForPhone($request, $otpService, $phone, $role);
    }

    private function sendLoginOtpForPhone(Request $request, PhoneOtpService $otpService, string $phone, UserRole $role = UserRole::Pharmacy): RedirectResponse
    {
        if (! $this->canSend($request, $phone, $role)) {
            return back()->withErrors(['phone' => 'Попробуйте отправить код позже.']);
        }
        $user = $this->phoneLoginUser($phone, true, $role);
        if ($user && ! $user->is_blocked && ! $this->usesDevelopmentOtp()) {
            $otpService->send('login', $phone, $role);
        }
        $request->session()->put($this->loginSessionKey($role), $phone);

        return redirect()->route($this->loginOtpFormRoute($role))->with('success', 'Если номер доступен для входа, код отправлен.');
    }

    public function loginOtpForm(): View
    {
        return $this->loginOtpFormForRole(UserRole::Pharmacy);
    }

    public function supplierLoginOtpForm(): View
    {
        return $this->loginOtpFormForRole(UserRole::Wholesaler);
    }

    private function loginOtpFormForRole(UserRole $role): View
    {
        abort_unless(session()->has($this->loginSessionKey($role)), 404);

        return view('auth.phone-otp', ['title' => 'Вход поставщика по телефону', 'route' => $this->loginOtpVerifyRoute($role), 'resend_route' => $this->loginOtpResendRoute($role), 'phone' => session($this->loginSessionKey($role))]);
    }

    public function verifyLoginOtp(VerifyPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        return $this->verifyLoginOtpForRole($request, $otpService, UserRole::Pharmacy);
    }

    public function verifySupplierLoginOtp(VerifyPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        return $this->verifyLoginOtpForRole($request, $otpService, UserRole::Wholesaler);
    }

    private function verifyLoginOtpForRole(VerifyPhoneOtpRequest $request, PhoneOtpService $otpService, UserRole $role): RedirectResponse
    {
        $phone = $request->validated('phone');
        $usesDevelopmentCode = $this->usesDevelopmentOtp() && hash_equals((string) config('auth.development_otp_code'), $request->validated('code'));

        if ($phone !== $request->session()->get($this->loginSessionKey($role)) || (! $usesDevelopmentCode && ! $otpService->consume('login', $phone, $role, $request->validated('code')))) {
            return back()->withErrors(['code' => 'Код недействителен или истёк.']);
        }
        $user = $this->phoneLoginUser($phone, false, $role);
        if (! $user) {
            return back()->withErrors(['code' => 'Код недействителен или истёк.']);
        }
        if ($role === UserRole::Wholesaler && ! $user->phone_verified_at) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }
        if ($this->activeSessionsFor($user)->isNotEmpty()) {
            $request->session()->put($this->pendingLoginSessionKey($role), $user->id);
            $request->session()->forget($this->loginSessionKey($role));

            return redirect()->route($this->sessionConfirmationRoute($role));
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget($this->loginSessionKey($role));

        return redirect()->intended(route($user->defaultLandingRouteName()));
    }

    public function sessionConfirmationForm(Request $request): View
    {
        return $this->sessionConfirmationFormForRole($request, UserRole::Pharmacy);
    }

    public function supplierSessionConfirmationForm(Request $request): View
    {
        return $this->sessionConfirmationFormForRole($request, UserRole::Wholesaler);
    }

    private function sessionConfirmationFormForRole(Request $request, UserRole $role): View
    {
        $user = $this->pendingPhoneUser($request, $role);
        $session = $this->activeSessionsFor($user)->first();

        abort_unless($session, 404);

        return view('auth.session-confirmation', [
            'session' => $session,
            'deviceSummary' => $this->userAgentFormatter->format($session->user_agent),
            'lastActivity' => now()->setTimestamp($session->last_activity)->format('d.m.Y H:i'),
            'confirmRoute' => $role === UserRole::Pharmacy ? 'login.session.confirm' : 'provider.session.confirm',
            'cancelRoute' => $role === UserRole::Pharmacy ? 'login.session.cancel' : 'provider.session.cancel',
        ]);
    }

    public function confirmSessionReplacement(Request $request): RedirectResponse
    {
        return $this->confirmSessionReplacementForRole($request, UserRole::Pharmacy);
    }

    public function confirmSupplierSessionReplacement(Request $request): RedirectResponse
    {
        return $this->confirmSessionReplacementForRole($request, UserRole::Wholesaler);
    }

    private function confirmSessionReplacementForRole(Request $request, UserRole $role): RedirectResponse
    {
        $user = $this->pendingPhoneUser($request, $role);

        $this->sessionQuery()->where('user_id', $user->id)->delete();
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget([$this->loginSessionKey($role), $this->pendingLoginSessionKey($role)]);

        if ($role === UserRole::Pharmacy) {
            return redirect()->route('catalog');
        }

        return redirect()->intended(route($user->defaultLandingRouteName()));
    }

    public function cancelSessionReplacement(Request $request): RedirectResponse
    {
        return $this->cancelSessionReplacementForRole($request, UserRole::Pharmacy);
    }

    public function cancelSupplierSessionReplacement(Request $request): RedirectResponse
    {
        return $this->cancelSessionReplacementForRole($request, UserRole::Wholesaler);
    }

    private function cancelSessionReplacementForRole(Request $request, UserRole $role): RedirectResponse
    {
        $this->pendingPhoneUser($request, $role);
        $request->session()->forget([$this->loginSessionKey($role), $this->pendingLoginSessionKey($role)]);

        return redirect()->route($role === UserRole::Pharmacy ? 'login' : 'provider.login')->with('warning', 'Вход отменён. Активный сеанс на другом устройстве сохранён.');
    }

    public function registerForm(): View
    {
        return view('auth.register');
    }

    public function register(SendPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        return $this->registerForRole($request, $otpService, UserRole::Pharmacy);
    }

    public function supplierRegister(SendPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        return $this->registerForRole($request, $otpService, UserRole::Wholesaler);
    }

    private function registerForRole(Request $request, PhoneOtpService $otpService, UserRole $role): RedirectResponse
    {
        $phone = $request->validated('phone');
        $user = $this->phoneLoginUser($phone, true, $role);

        if (! $user) {
            $request->session()->put($this->registrationSessionKey($role), ['phone' => $phone]);

            return redirect()->route($this->registrationDetailsRoute($role));
        }

        if ($user->organization?->status === 'pending' && ! $user->phone_verified_at) {
            $request->session()->put($this->registrationSessionKey($role), ['phone' => $phone, 'user_id' => $user->id]);

            return $this->sendRegistrationOtp($request, $otpService, $phone, $role);
        }

        return $this->sendLoginOtpForPhone($request, $otpService, $phone, $role);
    }

    public function registrationDetailsForm(): View
    {
        return $this->registrationDetailsFormForRole(UserRole::Pharmacy);
    }

    public function supplierRegistrationDetailsForm(): View
    {
        return $this->registrationDetailsFormForRole(UserRole::Wholesaler);
    }

    private function registrationDetailsFormForRole(UserRole $role): View
    {
        abort_unless(session()->has($this->registrationSessionKey($role).'.phone'), 404);

        return view('auth.register-name', [
            'phone' => session($this->registrationSessionKey($role).'.phone'),
            'title' => $role === UserRole::Pharmacy ? 'Название аптеки' : 'Название поставщика',
            'description' => $role === UserRole::Pharmacy ? 'Укажите название для новой заявки.' : 'Укажите название компании для новой заявки.',
            'field' => $role === UserRole::Pharmacy ? 'pharmacy_name' : 'supplier_name',
            'label' => $role === UserRole::Pharmacy ? 'Название аптеки' : 'Название компании',
            'route' => $role === UserRole::Pharmacy ? 'register.details.store' : 'provider.register.details.store',
        ]);
    }

    public function storeRegistrationDetails(Request $request, PhoneOtpService $otpService): RedirectResponse
    {
        return $this->storeRegistrationDetailsForRole($request, $otpService, UserRole::Pharmacy);
    }

    public function storeSupplierRegistrationDetails(Request $request, PhoneOtpService $otpService): RedirectResponse
    {
        return $this->storeRegistrationDetailsForRole($request, $otpService, UserRole::Wholesaler);
    }

    private function storeRegistrationDetailsForRole(Request $request, PhoneOtpService $otpService, UserRole $role): RedirectResponse
    {
        $registration = $request->session()->get($this->registrationSessionKey($role));
        abort_unless(is_array($registration) && isset($registration['phone']), 404);

        $nameField = $role === UserRole::Pharmacy ? 'pharmacy_name' : 'supplier_name';
        $data = $request->validate([$nameField => ['required', 'string', 'max:255']]);
        $phone = $registration['phone'];

        try {
            $user = DB::transaction(function () use ($phone, $data, $nameField, $role): User {
                $existingUser = $this->phoneLoginUser($phone, true, $role);
                if ($existingUser) {
                    return $existingUser;
                }

                $organization = Organization::create([
                    'name' => $data[$nameField],
                    'phone' => $phone,
                    'type' => $role === UserRole::Pharmacy ? OrganizationType::Pharmacy : OrganizationType::Wholesaler,
                    'supplier_mode' => $role === UserRole::Wholesaler ? TradeMode::Supplier->value : 'both',
                    'status' => 'pending',
                ]);
                $user = new User(['name' => $data[$nameField], 'phone' => $phone]);
                $user->forceFill([
                    'organization_id' => $organization->id,
                    'role' => $role,
                    'active_trade_mode' => $role === UserRole::Pharmacy ? TradeMode::Buyer : TradeMode::Supplier,
                ])->save();

                return $user;
            });
        } catch (QueryException $exception) {
            $user = $this->phoneLoginUser($phone, true, $role);
            if (! $user) {
                throw $exception;
            }
        }

        if ($user->organization?->status !== 'pending' || $user->phone_verified_at) {
            return $this->sendLoginOtpForPhone($request, $otpService, $phone, $role);
        }

        $request->session()->put($this->registrationSessionKey($role), ['phone' => $phone, 'user_id' => $user->id]);

        return $this->sendRegistrationOtp($request, $otpService, $phone, $role);
    }

    public function registerOtpForm(): View
    {
        return $this->registerOtpFormForRole(UserRole::Pharmacy);
    }

    public function supplierRegisterOtpForm(): View
    {
        return $this->registerOtpFormForRole(UserRole::Wholesaler);
    }

    private function registerOtpFormForRole(UserRole $role): View
    {
        abort_unless(session()->has($this->registrationSessionKey($role)), 404);

        return view('auth.phone-otp', ['title' => 'Подтвердите телефон', 'route' => $role === UserRole::Pharmacy ? 'register.otp.verify' : 'provider.register.otp.verify', 'resend_route' => $role === UserRole::Pharmacy ? 'register.otp.resend' : 'provider.register.otp.resend', 'phone' => session($this->registrationSessionKey($role).'.phone')]);
    }

    public function verifyRegistrationOtp(VerifyPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        return $this->verifyRegistrationOtpForRole($request, $otpService, UserRole::Pharmacy);
    }

    public function verifySupplierRegistrationOtp(VerifyPhoneOtpRequest $request, PhoneOtpService $otpService): RedirectResponse
    {
        return $this->verifyRegistrationOtpForRole($request, $otpService, UserRole::Wholesaler);
    }

    private function verifyRegistrationOtpForRole(VerifyPhoneOtpRequest $request, PhoneOtpService $otpService, UserRole $role): RedirectResponse
    {
        $registration = $request->session()->get($this->registrationSessionKey($role));
        $phone = $request->validated('phone');
        $usesDevelopmentCode = $this->usesDevelopmentOtp() && hash_equals((string) config('auth.development_otp_code'), $request->validated('code'));
        $userId = is_array($registration) ? $registration['user_id'] ?? null : null;
        $user = is_numeric($userId)
            ? User::whereKey($userId)->where('phone', $phone)->where('role', $role)->whereNull('phone_verified_at')->whereHas('organization', fn ($query) => $query->where('status', 'pending'))->first()
            : null;
        if (! $registration || $phone !== $registration['phone'] || ! $user || (! $usesDevelopmentCode && ! $otpService->consume('registration', $phone, $role, $request->validated('code')))) {
            return back()->withErrors(['code' => 'Код недействителен или истёк.']);
        }

        $user = DB::transaction(function () use ($user): User {
            $pendingUser = User::query()
                ->lockForUpdate()
                ->whereKey($user->id)
                ->whereNull('phone_verified_at')
                ->whereHas('organization', fn ($query) => $query->where('status', 'pending'))
                ->firstOrFail();
            $pendingUser->forceFill(['phone_verified_at' => now()])->save();

            return $pendingUser;
        });
        $request->session()->forget($this->registrationSessionKey($role));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('subscription.create')->with('success', 'Телефон подтверждён. Заявка ожидает проверки администратора.');
    }

    public function resend(Request $request, PhoneOtpService $otpService, string $purpose): RedirectResponse
    {
        $state = $request->session()->get("phone_otp.{$purpose}");
        $phone = is_array($state) ? $state['phone'] : $state;
        if (! $phone || ! $this->canSend($request, $phone, UserRole::Pharmacy) || ! $otpService->send($purpose === 'login' ? 'login' : 'registration', $phone, UserRole::Pharmacy)) {
            return back()->withErrors(['phone' => 'Не удалось отправить код. Попробуйте позже.']);
        }

        return back()->with('success', 'Новый код отправлен.');
    }

    public function resendSupplier(Request $request, PhoneOtpService $otpService, string $purpose): RedirectResponse
    {
        $key = $purpose === 'login' ? $this->loginSessionKey(UserRole::Wholesaler) : $this->registrationSessionKey(UserRole::Wholesaler);
        $state = $request->session()->get($key);
        $phone = is_array($state) ? $state['phone'] : $state;
        if (! $phone || ! $this->canSend($request, $phone, UserRole::Wholesaler) || ! $otpService->send($purpose === 'login' ? 'login' : 'registration', $phone, UserRole::Wholesaler)) {
            return back()->withErrors(['phone' => 'Не удалось отправить код. Попробуйте позже.']);
        }

        return back()->with('success', 'Новый код отправлен.');
    }

    private function sendRegistrationOtp(Request $request, PhoneOtpService $otpService, string $phone, UserRole $role = UserRole::Pharmacy): RedirectResponse
    {
        if (! $this->canSend($request, $phone, $role) || (! $this->usesDevelopmentOtp() && ! $otpService->send('registration', $phone, $role))) {
            return back()->withErrors(['phone' => 'Не удалось отправить код. Попробуйте позже.']);
        }

        return redirect()->route($role === UserRole::Pharmacy ? 'register.otp.form' : 'provider.register.otp.form');
    }

    private function canSend(Request $request, string $phone, UserRole $role): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        foreach ([['resend', 1, 60], ['quarter-hour', 5, 900], ['day', 10, 86400]] as [$period, $max, $seconds]) {
            foreach (['phone:'.$role->value.':'.$phone, 'ip:'.$role->value.':'.$request->ip()] as $identity) {
                if (RateLimiter::tooManyAttempts("otp-send:{$period}:{$identity}", $max)) {
                    return false;
                }
            }
        }
        foreach ([['resend', 60], ['quarter-hour', 900], ['day', 86400]] as [$period, $seconds]) {
            foreach (['phone:'.$role->value.':'.$phone, 'ip:'.$role->value.':'.$request->ip()] as $identity) {
                RateLimiter::hit("otp-send:{$period}:{$identity}", $seconds);
            }
        }

        return true;
    }

    private function usesDevelopmentOtp(): bool
    {
        return app()->environment(['local', 'testing']) && filled(config('auth.development_otp_code'));
    }

    private function phoneLoginUser(string $phone, bool $includeBlocked = true, UserRole $role = UserRole::Pharmacy): ?User
    {
        $query = User::where('phone', $phone);
        $query->where('role', $role);
        if (! $includeBlocked) {
            $query->where('is_blocked', false);
        }

        return $query->first();
    }

    private function pendingPhoneUser(Request $request, UserRole $role): User
    {
        $userId = $request->session()->get($this->pendingLoginSessionKey($role));
        $user = is_numeric($userId)
            ? User::whereKey($userId)->where('role', $role)->first()
            : null;

        abort_unless($user, 404);

        return $user;
    }

    private function loginSessionKey(UserRole $role): string
    {
        return $role === UserRole::Pharmacy ? 'phone_otp.login' : 'phone_otp.supplier_login';
    }

    private function registrationSessionKey(UserRole $role): string
    {
        return $role === UserRole::Pharmacy ? 'phone_otp.registration' : 'phone_otp.supplier_registration';
    }

    private function pendingLoginSessionKey(UserRole $role): string
    {
        return $role === UserRole::Pharmacy ? 'phone_otp.pending_login_user_id' : 'phone_otp.pending_supplier_login_user_id';
    }

    private function loginOtpFormRoute(UserRole $role): string
    {
        return $role === UserRole::Pharmacy ? 'login.otp.form' : 'provider.otp.form';
    }

    private function loginOtpVerifyRoute(UserRole $role): string
    {
        return $role === UserRole::Pharmacy ? 'login.otp.verify' : 'provider.otp.verify';
    }

    private function loginOtpResendRoute(UserRole $role): string
    {
        return $role === UserRole::Pharmacy ? 'login.otp.resend' : 'provider.otp.resend';
    }

    private function registrationDetailsRoute(UserRole $role): string
    {
        return $role === UserRole::Pharmacy ? 'register.details.form' : 'provider.register.details.form';
    }

    private function sessionConfirmationRoute(UserRole $role): string
    {
        return $role === UserRole::Pharmacy ? 'login.session.confirmation' : 'provider.session.confirmation';
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
