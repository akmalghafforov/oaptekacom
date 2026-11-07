<?php

namespace Database\Factories;

use App\Models\Promotion;
use App\Models\PromotionQuoteRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromotionQuoteRequest> */
class PromotionQuoteRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['promotion_id' => Promotion::factory(), 'user_id' => User::factory()->pharmacy(), 'message' => fake()->sentence(),
            'contact_snapshot' => ['name' => 'Покупатель', 'organization' => 'Аптека', 'phone' => '+992900000001', 'email' => null, 'organization_phone' => null, 'promotion_title' => 'Акция', 'item_name' => null]];
    }
}
