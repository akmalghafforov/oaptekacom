<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SupplierRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierRequest>
 */
class SupplierRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->pharmacy(),
            'buyer_organization_id' => Organization::factory()->pharmacy(),
            'supplier_organization_id' => Organization::factory()->wholesaler(),
            'buyer_name' => fake()->company(),
            'supplier_name' => fake()->company(),
            'item_count' => fake()->numberBetween(1, 10),
            'total' => fake()->randomFloat(2, 10, 1000),
            'shared_via' => 'clipboard',
            'shared_at' => now(),
        ];
    }
}
