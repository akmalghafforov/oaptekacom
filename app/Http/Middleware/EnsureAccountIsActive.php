<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        if ($u?->is_blocked) {
            auth()->logout();

            return redirect()->route('login')->withErrors(['email' => 'Учётная запись заблокирована.']);
        } if ($u?->password_change_required && ! $request->routeIs('profile.*')) {
            return redirect()->route('profile.edit')->with('warning', 'Перед продолжением смените временный пароль.');
        } if (! $u?->hasActiveSubscription()) {
            return redirect()->route('subscription.create')->with('warning', 'Требуется активная подписка или подтверждение организации.');
        }

        return $next($request);
    }
}
