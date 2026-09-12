<?php

namespace Database\Factories;

use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory()->wholesaler(), 'medicine_id' => Medicine::factory(), 'price' => fake()->randomFloat(2, 1, 1000), 'stock' => 0, 'quantity' => null, 'is_active' => false];
    }
}
