<?php

namespace App\Policies;

use App\Models\PriceListImport;
use App\Models\User;

class PriceListImportPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->canSupply();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PriceListImport $priceListImport): bool
    {
        return $this->belongsToUser($user, $priceListImport);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->canSupply();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PriceListImport $priceListImport): bool
    {
        return $this->belongsToUser($user, $priceListImport);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PriceListImport $priceListImport): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, PriceListImport $priceListImport): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, PriceListImport $priceListImport): bool
    {
        return false;
    }

    public function retry(User $user, PriceListImport $priceListImport): bool
    {
        return $this->belongsToUser($user, $priceListImport);
    }

    private function belongsToUser(User $user, PriceListImport $import): bool
    {
        return $user->isAdmin() || ($user->canSupply() && $user->organization_id === $import->supplier_organization_id);
    }
}
