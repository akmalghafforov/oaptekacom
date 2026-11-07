<?php

namespace Database\Factories;

use App\Models\Promotion;
use App\Models\PromotionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromotionItem> */
class PromotionItemFactory extends Factory
{
    public function definition(): array
    {
        return ['promotion_id' => Promotion::factory(), 'name' => fake()->word(), 'price' => '100.00', 'price_mode' => 'final', 'minimum_quantity' => 1, 'in_stock' => true, 'stock' => null];
    }

    public function quoteOnly(): static
    {
        return $this->state(['price' => null]);
    }
}
