<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->isAdmin() || in_array($user->organization_id, [$order->buyer_organization_id, $order->supplier_organization_id], true);
    }

    public function update(User $user, Order $order): bool
    {
        return $user->isAdmin() || ($user->canSupply() && $user->organization_id === $order->supplier_organization_id);
    }
}
