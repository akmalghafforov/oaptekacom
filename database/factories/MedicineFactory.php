<?php

namespace Database\Factories;

use App\Models\Medicine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Medicine>
 */
class MedicineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(3, true);

        return ['name' => $name, 'normalized_name' => mb_strtolower($name), 'search_text' => mb_strtolower($name), 'manufacturer' => fake()->company()];
    }
}
