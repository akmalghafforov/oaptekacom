<?php

namespace Database\Factories;

use App\Enums\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlanPrice>
 */
class SubscriptionPlanPriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan' => fake()->randomElement([SubscriptionPlan::Base, SubscriptionPlan::Premium]),
            'daily_price' => fake()->randomFloat(2, 1, 100),
        ];
    }
}
