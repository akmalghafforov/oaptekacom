<?php

namespace App\Providers;

use App\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(fn (User $user): ?bool => $user->isAdmin() ? true : null);
        View::composer('layouts.app', function ($view): void {
            $user = auth()->user();
            $cartTotal = $user?->canBuy() ? (int) CartItem::query()->whereHas('cart', fn ($query) => $query->where('user_id', $user->id))->sum('quantity') : 0;
            $view->with('navigationCartTotal', $cartTotal);
        });
    }
}
