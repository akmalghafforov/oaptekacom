<?php

namespace Database\Factories;

use App\Models\ActivationHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivationHistory>
 */
class ActivationHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['phone' => '+992'.fake()->unique()->numerify('9########'), 'organization_id' => null, 'demo_used_at' => null];
    }
}
