<?php

namespace Database\Factories;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTerm;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->pharmacy(),
            'assigned_by' => User::factory()->admin(),
            'plan' => SubscriptionPlan::Base,
            'term' => SubscriptionTerm::Month,
            'starts_on' => now('Asia/Dushanbe')->startOfDay(),
            'ends_on' => now('Asia/Dushanbe')->startOfDay()->addMonthNoOverflow()->subDay(),
            'daily_price' => '1.00',
            'total_price' => '30.00',
            'status' => SubscriptionStatus::Active,
        ];
    }
}
