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
                ->when(! $user->isAdminMode(), fn (Builder $query): Builder => $user->canSupply() ? $query->where('supplier_organization_id', $user->activeOrganizationId()) : $query->whereIn('buyer_organization_id', $user->purchaseOrganizationIds()))
                ->latest()
                ->take(5)
                ->get(),
            'organization' => $user->activeOrganization(),
            'subscription' => $user->subscriptions()->where('status', SubscriptionStatus::Active)->latest()->first(),
        ]);
    }
}
