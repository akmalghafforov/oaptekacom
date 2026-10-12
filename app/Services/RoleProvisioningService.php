<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Support\Facades\DB;

class RoleProvisioningService
{
    /** @return array{assignments: int, organizations: int, activated: int} */
    public function provision(User $user): array
    {
        $counts = DB::transaction(function () use ($user): array {
            $counts = ['assignments' => 0, 'organizations' => 0, 'activated' => 0];
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $role = UserRole::tryFrom($user->getRawOriginal('role'));
            if (! $role) {
                throw new \LogicException("User {$user->id}: invalid primary role.");
            }
            if ($role !== UserRole::Admin) {
                $organization = Organization::query()->lockForUpdate()->find($user->organization_id);
                if (! $organization || $organization->type->value !== $role->value) {
                    throw new \LogicException("User {$user->id}: missing or mismatched primary organization.");
                }
            }
            $primary = $user->roleAssignments()->firstOrCreate(['role' => $role], ['organization_id' => $role === UserRole::Admin ? null : $organization->id]);
            if ($primary->organization_id !== ($role === UserRole::Admin ? null : $organization->id)) {
                throw new \LogicException("User {$user->id}: primary assignment organization mismatch.");
            }
            $counts['assignments'] += (int) $primary->wasRecentlyCreated;
            if ($role === UserRole::Admin) {
                foreach ([UserRole::Wholesaler, UserRole::Pharmacy] as $businessRole) {
                    if (! $user->roleAssignments()->where('role', $businessRole)->exists()) {
                        $businessOrganization = Organization::create([
                            'name' => $user->name,
                            'contact_email' => $user->email,
                            'type' => $businessRole->value,
                            'status' => 'active',
                            'setup_required' => false,
                        ]);
                        $user->roleAssignments()->create(['role' => $businessRole, 'organization_id' => $businessOrganization->id]);
                        $counts['assignments']++;
                        $counts['organizations']++;
                    } else {
                        $businessOrganization = Organization::query()->lockForUpdate()->find($user->roleAssignments()->where('role', $businessRole)->value('organization_id'));
                        if ($businessOrganization && $this->needsActivation($businessOrganization)) {
                            $businessOrganization->status = 'active';
                            $businessOrganization->setup_required = false;
                            $counts['activated'] += Organization::query()->whereKey($businessOrganization->id)->whereIn('status', ['pending', 'active'])->update(['status' => 'active', 'setup_required' => false]);
                        }
                        if ($businessOrganization?->type === OrganizationType::Wholesaler && $businessOrganization->status === 'active') {
                            $counts['activated'] += $this->activateLinkedPharmacy($businessOrganization);
                        }
                    }
                }
            } elseif ($role === UserRole::Wholesaler) {
                if (! $organization->linked_pharmacy_organization_id) {
                    $pharmacy = Organization::create([
                        'name' => $organization->name,
                        'city' => $organization->city,
                        'type' => OrganizationType::Pharmacy,
                        'status' => $organization->status === 'active' ? 'active' : 'pending',
                    ]);
                    $organization->update(['linked_pharmacy_organization_id' => $pharmacy->id]);
                    $counts['organizations']++;
                }
                if ($organization->linkedPharmacyOrganization?->type !== OrganizationType::Pharmacy) {
                    throw new \LogicException("User {$user->id}: invalid linked pharmacy organization.");
                }
                if ($organization->status === 'active') {
                    $counts['activated'] += $this->activateLinkedPharmacy($organization);
                }
                $assignment = $user->roleAssignments()->firstOrCreate(['role' => UserRole::Pharmacy], ['organization_id' => $organization->linked_pharmacy_organization_id]);
                $counts['assignments'] += (int) $assignment->wasRecentlyCreated;
            }

            return $counts;
        });
        $user->unsetRelation('roleAssignments');

        return $counts;
    }

    /**
     * @param  array<int, true>  $plannedSupplierPharmacies
     * @param  array<int, true>  $plannedActivations
     * @return array{assignments: int, organizations: int, activated: int}
     */
    public function inspect(User $user, array &$plannedSupplierPharmacies, array &$plannedActivations = []): array
    {
        $role = UserRole::tryFrom($user->getRawOriginal('role'));
        if (! $role) {
            throw new \LogicException("User {$user->id}: invalid primary role.");
        }
        $organization = $user->organization;
        if ($role !== UserRole::Admin && (! $organization || $organization->type->value !== $role->value)) {
            throw new \LogicException("User {$user->id}: missing or mismatched primary organization.");
        }
        $assignments = $user->roleAssignments()->get()->keyBy(fn (UserRoleAssignment $assignment): string => $assignment->role->value);
        $primary = $assignments->get($role->value);
        if ($primary && $primary->organization_id !== ($role === UserRole::Admin ? null : $organization->id)) {
            throw new \LogicException("User {$user->id}: primary assignment organization mismatch.");
        }
        $counts = ['assignments' => $primary ? 0 : 1, 'organizations' => 0, 'activated' => 0];
        if ($role === UserRole::Admin) {
            foreach ([UserRole::Wholesaler, UserRole::Pharmacy] as $businessRole) {
                if (! $assignments->has($businessRole->value)) {
                    $counts['assignments']++;
                    $counts['organizations']++;
                } else {
                    $businessOrganization = $assignments->get($businessRole->value)->organization;
                    if ($businessOrganization && $this->needsActivation($businessOrganization) && ! isset($plannedActivations[$businessOrganization->id])) {
                        $counts['activated']++;
                        $plannedActivations[$businessOrganization->id] = true;
                    }
                    if ($businessOrganization?->type === OrganizationType::Wholesaler && ($businessOrganization->status === 'active' || isset($plannedActivations[$businessOrganization->id]))) {
                        $pharmacy = $businessOrganization->linkedPharmacyOrganization;
                        if ($pharmacy && $this->needsActivation($pharmacy) && ! isset($plannedActivations[$pharmacy->id])) {
                            $counts['activated']++;
                            $plannedActivations[$pharmacy->id] = true;
                        }
                    }
                }
            }
        } elseif ($role === UserRole::Wholesaler) {
            if ($organization->linked_pharmacy_organization_id && $organization->linkedPharmacyOrganization?->type !== OrganizationType::Pharmacy) {
                throw new \LogicException("User {$user->id}: invalid linked pharmacy organization.");
            }
            if (! $organization->linked_pharmacy_organization_id && ! isset($plannedSupplierPharmacies[$organization->id])) {
                $counts['organizations']++;
                $plannedSupplierPharmacies[$organization->id] = true;
            }
            $pharmacy = $organization->linkedPharmacyOrganization;
            if (($organization->status === 'active' || isset($plannedActivations[$organization->id])) && $pharmacy && $this->needsActivation($pharmacy) && ! isset($plannedActivations[$pharmacy->id])) {
                $counts['activated']++;
                $plannedActivations[$pharmacy->id] = true;
            }
            $counts['assignments'] += $assignments->has(UserRole::Pharmacy->value) ? 0 : 1;
        }

        return $counts;
    }

    private function activateLinkedPharmacy(Organization $organization): int
    {
        if (! $organization->linked_pharmacy_organization_id) {
            return 0;
        }

        $pharmacy = Organization::query()->lockForUpdate()->findOrFail($organization->linked_pharmacy_organization_id);
        if ($pharmacy->type !== OrganizationType::Pharmacy) {
            throw new \LogicException("Organization {$organization->id}: invalid linked pharmacy organization.");
        }
        if (! $this->needsActivation($pharmacy)) {
            return 0;
        }
        $pharmacy->update(['status' => 'active', 'setup_required' => false]);

        return 1;
    }

    private function needsActivation(Organization $organization): bool
    {
        return $organization->status === 'pending' || ($organization->status === 'active' && $organization->setup_required);
    }
}
