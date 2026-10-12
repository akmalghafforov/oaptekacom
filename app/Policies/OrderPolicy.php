<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->isAdminMode() || ($user->canSupply() ? $user->activeOrganizationId() === $order->supplier_organization_id : ($user->canBuy() && in_array($order->buyer_organization_id, $user->purchaseOrganizationIds(), true)));
    }

    public function update(User $user, Order $order): bool
    {
        return $user->isAdminMode() || ($user->canSupply() && $user->activeOrganizationId() === $order->supplier_organization_id);
    }
}
