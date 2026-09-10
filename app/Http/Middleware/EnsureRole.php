<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        abort_unless(in_array($request->user()->role->value, $roles, true), 403);

        return $next($request);
    }
}
