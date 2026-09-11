<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Models\Order;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $u = request()->user();

        return view('dashboard', ['orders' => Order::when(! $u->isAdmin(), fn ($q) => $q->where('buyer_organization_id', $u->organization_id)->orWhere('supplier_organization_id', $u->organization_id))->latest()->take(5)->get(), 'organization' => $u->organization, 'subscription' => $u->subscriptions()->where('status', SubscriptionStatus::Active)->latest()->first()]);
    }
}
