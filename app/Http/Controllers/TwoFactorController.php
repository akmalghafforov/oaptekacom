<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use PragmaRX\Google2FALaravel\Facade as Google2FA;

class TwoFactorController extends Controller
{
    public function enroll(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403);
        $secret = $user->totp_secret ?: Google2FA::generateSecretKey();
        if (! $user->totp_secret) {
            $user->forceFill(['totp_secret' => $secret])->save();
        }

        return view('auth.two-factor', ['secret' => $secret, 'confirmed' => (bool) $user->two_factor_confirmed_at]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        $key = 'totp:'.$user->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['code' => 'Слишком много попыток.']);
        }
        if (! Google2FA::verifyKey($user->totp_secret, $request->string('code')->toString())) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['code' => 'Неверный код.']);
        }
        $codes = collect(range(1, 8))->map(fn () => strtoupper(str()->random(10)))->all();
        $user->forceFill(['totp_enabled' => true, 'two_factor_confirmed_at' => now(), 'recovery_codes' => array_map(fn ($code) => Hash::make($code), $codes)])->save();
        RateLimiter::clear($key);

        return redirect()->route($user->defaultLandingRouteName())->with('success', '2FA включена. Сохраните коды восстановления: '.implode(', ', $codes));
    }

    public function recovery(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        foreach ($user->recovery_codes ?? [] as $index => $hash) {
            if (Hash::check($request->string('code')->toString(), $hash)) {
                $codes = $user->recovery_codes;
                unset($codes[$index]);
                $user->forceFill(['recovery_codes' => array_values($codes), 'two_factor_confirmed_at' => now()])->save();

                return redirect()->route($user->defaultLandingRouteName());
            }
        }

        return back()->withErrors(['code' => 'Код восстановления недействителен.']);
    }
}
