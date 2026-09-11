<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\OneTimePassword;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OneTimePassword>
 */
class OneTimePasswordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['purpose' => 'login', 'account_type' => UserRole::Pharmacy, 'phone' => '+992900000001', 'code_hash' => bcrypt('123456'), 'transaction_id' => fake()->uuid(), 'status' => 'sent', 'sent_at' => now(), 'expires_at' => now()->addMinutes(5), 'attempts' => 0];
    }
}
