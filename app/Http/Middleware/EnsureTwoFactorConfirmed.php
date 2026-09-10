<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isAdmin() && ! $user->two_factor_confirmed_at) {
            return redirect()->route('two-factor.enroll');
        }

        return $next($request);
    }
}
