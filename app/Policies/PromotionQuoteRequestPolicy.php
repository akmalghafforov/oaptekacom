<?php

namespace App\Policies;

use App\Models\Promotion;
use App\Models\PromotionQuoteRequest;
use App\Models\User;

class PromotionQuoteRequestPolicy
{
    public function create(User $user, Promotion $promotion): bool
    {
        return $user->can('buy', $promotion);
    }

    public function view(User $user, PromotionQuoteRequest $quoteRequest): bool
    {
        return $user->isAdminMode() || ($user->canBuy() && $quoteRequest->user_id === $user->id)
            || $user->can('manage', $quoteRequest->promotion);
    }
}
