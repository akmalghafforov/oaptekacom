<?php

namespace App\Policies;

use App\Models\Offer;
use App\Models\User;

class OfferPolicy
{
    public function view(User $user, Offer $offer): bool
    {
        return $offer->is_active || $user->isAdmin() || $user->organization_id === $offer->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->canSupply();
    }

    public function update(User $user, Offer $offer): bool
    {
        return $user->isAdmin() || ($user->canSupply() && $user->organization_id === $offer->organization_id);
    }

    public function delete(User $user, Offer $offer): bool
    {
        return $this->update($user, $offer);
    }
}
