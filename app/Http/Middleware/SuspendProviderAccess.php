<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SuspendProviderAccess
{
    public const string MESSAGE = 'Регистрация и вход для поставщиков временно приостановлены.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth.provider_access_suspended')) {
            return $next($request);
        }

        $isProvider = $request->user()?->role === UserRole::Wholesaler;
        if (! $isProvider && ! $request->routeIs('provider.*')) {
            return $next($request);
        }

        if ($isProvider) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $request->session()->forget([
            'phone_otp.supplier_login',
            'phone_otp.supplier_registration',
            'phone_otp.pending_supplier_login_user_id',
        ]);

        return $request->expectsJson()
            ? response()->json(['message' => self::MESSAGE], 503)
            : redirect()->route('login')->with('warning', self::MESSAGE);
    }
}
