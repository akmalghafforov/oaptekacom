<?php

namespace App\Http\Middleware;

use App\Services\LoginSessionLifetime;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceLoginSessionLifetime
{
    public function __construct(private readonly LoginSessionLifetime $lifetime) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard();
        if ($guard->check()) {
            if ($guard->viaRemember() || $this->lifetime->isExpired($request->session())) {
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $request->expectsJson()
                    ? response()->json(['message' => 'Unauthenticated.'], 401)
                    : redirect()->route('login');
            }

            $this->lifetime->extend($request->session());
        }

        return $next($request);
    }
}
