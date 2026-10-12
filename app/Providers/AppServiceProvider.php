<?php

namespace App\Providers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\SupplierRequest;
use App\Models\User;
use App\Services\AccountModeContext;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AccountModeContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('account-modes.enabled') && ! $this->app->runningUnitTests()) {
            $lockStore = Cache::store(config('session.block_store'))->getStore();
            if (! config('session.block') || in_array(config('session.driver'), ['cookie', 'array'], true) || config('cache.stores.'.config('session.block_store').'.driver') === 'array' || ! $lockStore instanceof LockProvider) {
                throw new \LogicException('Account modes require server-side sessions and an atomic session lock store.');
            }
            if (config('session.block_lock_seconds') <= (int) ini_get('max_execution_time')) {
                throw new \LogicException('Session lock lifetime must exceed the HTTP execution timeout.');
            }
        }
        Gate::before(fn (User $user, string $ability, array $arguments): ?bool => $user->isAdminMode() && ! in_array(is_object($arguments[0] ?? null) ? $arguments[0]::class : ($arguments[0] ?? null), [Cart::class, SupplierRequest::class], true) ? true : null);
        View::composer('layouts.app', function ($view): void {
            $user = auth()->user();
            if ($user && config('account-modes.enabled')) {
                $user->loadMissing('roleAssignments');
            }
            $cartTotal = $user?->canBuy() ? (int) CartItem::query()->whereHas('cart', fn ($query) => $query->where('user_id', $user->id))->sum('quantity') : 0;
            $view->with('navigationCartTotal', $cartTotal);
        });
    }
}
