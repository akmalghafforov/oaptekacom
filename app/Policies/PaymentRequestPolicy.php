<?php

namespace App\Policies;

use App\Models\PaymentRequest;
use App\Models\User;

class PaymentRequestPolicy
{
    public function view(User $user, PaymentRequest $paymentRequest): bool
    {
        return $user->isAdmin() || $user->organization_id === $paymentRequest->organization_id;
    }

    public function create(User $user): bool
    {
        return ! $user->isAdmin() && ! $user->isWholesaler() && $user->canBuy();
    }
}
