<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('dashboard', [
            'orders' => Order::query()
                ->when(! $user->isAdmin(), fn (Builder $query): Builder => $query->where('buyer_organization_id', $user->organization_id)->orWhere('supplier_organization_id', $user->organization_id))
                ->latest()
                ->take(5)
                ->get(),
            'organization' => $user->organization,
            'subscription' => $user->subscriptions()->where('status', SubscriptionStatus::Active)->latest()->first(),
        ]);
    }
}
