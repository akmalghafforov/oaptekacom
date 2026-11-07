<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionContribution;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromotionContribution> */
class PromotionContributionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory()->withParticipation()->published(),
            'order_id' => fn (array $attributes): int => Order::factory()->create([
                'supplier_organization_id' => Promotion::findOrFail($attributes['promotion_id'])->organization_id,
            ])->id,
            'pharmacy_organization_id' => fn (array $attributes): int => Order::findOrFail($attributes['order_id'])->buyer_organization_id,
            'amount' => '100.00',
        ];
    }
}
