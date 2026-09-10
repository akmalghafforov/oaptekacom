<?php

namespace Database\Factories;

use App\Enums\OrganizationType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return ['name' => fake()->company(), 'type' => OrganizationType::Pharmacy, 'status' => 'active', 'supplier_mode' => 'both', 'phone' => fake()->unique()->e164PhoneNumber(), 'subscription_until' => now()->addMonth()];
    }

    public function pharmacy(): static
    {
        return $this->state(['type' => OrganizationType::Pharmacy]);
    }

    public function wholesaler(): static
    {
        return $this->state(['type' => OrganizationType::Wholesaler, 'subscription_until' => null]);
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending', 'subscription_until' => null]);
    }

    public function blocked(): static
    {
        return $this->state(['status' => 'blocked', 'subscription_until' => null]);
    }
}
