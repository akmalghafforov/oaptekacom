<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->is_blocked) {
            auth()->logout();

            return redirect()->route('login')->withErrors(['email' => 'Учётная запись заблокирована.']);
        }
        if ($request->routeIs('admin.*')) {
            return $next($request);
        }
        if (! $user?->isCustomer() && $user?->password_change_required && ! $request->routeIs('profile.*')) {
            return redirect()->route('profile.edit')->with('warning', 'Перед продолжением смените временный пароль.');
        }
        if (! $user?->hasActiveSubscription()) {
            return redirect()->route('subscription.create')->with('warning', 'Требуется активная подписка или подтверждение организации.');
        }

        return $next($request);
    }
}
