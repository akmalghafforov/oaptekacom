<?php

namespace App\Http\Middleware;

use App\Enums\ModuleKey;
use App\Services\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleIsEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $key = ModuleKey::tryFrom($module);
        abort_unless($key && app(ModuleRegistry::class)->available($request->user(), $key), 403);

        return $next($request);
    }
}
