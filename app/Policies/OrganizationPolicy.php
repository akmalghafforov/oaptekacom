<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->isAdminMode() || $user->activeOrganizationId() === $organization->id;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->view($user, $organization);
    }
}
