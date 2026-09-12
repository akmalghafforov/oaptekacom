<?php

namespace Database\Factories;

use App\Models\Medicine;
use App\Models\Organization;
use App\Models\SupplierProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierProduct>
 */
class SupplierProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(3, true);

        return ['supplier_organization_id' => Organization::factory()->wholesaler(), 'medicine_id' => Medicine::factory(), 'original_name' => $name, 'normalized_name' => mb_strtolower($name), 'match_key' => fake()->unique()->uuid()];
    }
}
