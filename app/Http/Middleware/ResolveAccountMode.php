<?php

namespace App\Http\Middleware;

use App\Services\AccountModeContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAccountMode
{
    public function __construct(private AccountModeContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->reset();
        if (! config('account-modes.enabled') || ! $request->user() || $request->routeIs('logout', 'legal.*')) {
            return $next($request);
        }
        $request->user()->unsetRelation('organization');
        $this->context->resolve($request);
        if (! $request->routeIs('logout')) {
            abort_if($request->user()->is_blocked || $request->user()->organization?->status === 'blocked', 403, 'Учётная запись заблокирована.');
            if (! $request->routeIs('two-factor.*') && ! app()->environment('local') && config('auth.admin_two_factor_enabled') && $request->user()->isAdmin() && ! $request->user()->two_factor_confirmed_at) {
                return redirect()->route('two-factor.enroll');
            }
            if (! $request->isMethodSafe() && ! $request->routeIs('two-factor.*')) {
                $version = $request->header('X-Mode-Version', $request->input('_mode_version'));
                abort_unless(is_string($version) && hash_equals($this->context->version, $version), 409, 'Режим изменился. Обновите страницу.');
            }
        }

        return $next($request);
    }
}
