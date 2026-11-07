<?php

namespace Database\Factories;

use App\Models\Promotion;
use App\Models\PromotionPrize;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromotionPrize> */
class PromotionPrizeFactory extends Factory
{
    public function definition(): array
    {
        return ['promotion_id' => Promotion::factory(), 'name' => fake()->words(3, true), 'description' => null, 'quantity' => 1];
    }
}
