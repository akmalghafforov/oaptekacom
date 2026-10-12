<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserRoleAssignment> */
class UserRoleAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->pharmacy(), 'role' => UserRole::Wholesaler, 'organization_id' => Organization::factory()->wholesaler()];
    }
}
