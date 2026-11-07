<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Promotion;
use App\Models\PromotionParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromotionParticipant> */
class PromotionParticipantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory()->withParticipation()->published(),
            'pharmacy_organization_id' => Organization::factory()->pharmacy(),
            'qualified_at' => now(), 'is_eligible' => true,
            'qualifying_total' => '100.00', 'largest_order_value' => '100.00', 'qualifying_order_count' => 1,
        ];
    }
}
