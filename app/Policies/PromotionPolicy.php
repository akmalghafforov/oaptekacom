<?php

namespace App\Policies;

use App\Enums\PromotionStatus;
use App\Models\Promotion;
use App\Models\User;

class PromotionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasActiveSubscription();
    }

    public function view(User $user, Promotion $promotion): bool
    {
        return $this->manage($user, $promotion) || ($this->viewAny($user)
            && $promotion->status === PromotionStatus::Published
            && $promotion->organization->status === 'active'
            && ! $promotion->organization->setup_required && ! $promotion->organization->price_list_hidden);
    }

    public function create(User $user): bool
    {
        return $user->isAdminMode() || ($user->canSupply() && $user->activeOrganization()?->status === 'active' && ! $user->activeOrganization()->setup_required);
    }

    public function manage(User $user, Promotion $promotion): bool
    {
        return $this->create($user) && ($user->isAdminMode() || $user->activeOrganizationId() === $promotion->organization_id);
    }

    public function update(User $user, Promotion $promotion): bool
    {
        return $this->manage($user, $promotion) && $promotion->status !== PromotionStatus::PendingReview;
    }

    public function submit(User $user, Promotion $promotion): bool
    {
        return $this->manage($user, $promotion) && $promotion->status === PromotionStatus::Draft;
    }

    public function withdraw(User $user, Promotion $promotion): bool
    {
        return $this->manage($user, $promotion) && $promotion->status === PromotionStatus::PendingReview;
    }

    public function review(User $user, Promotion $promotion): bool
    {
        return $user->isAdminMode() && $promotion->status === PromotionStatus::PendingReview;
    }

    public function buy(User $user, Promotion $promotion): bool
    {
        return $user->canBuy() && $user->hasActiveSubscription() && $this->view($user, $promotion) && $promotion->isActive();
    }
}
