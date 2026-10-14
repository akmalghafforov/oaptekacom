<?php

namespace Database\Factories;

use App\Models\GmailConnection;
use App\Models\User;
use App\Services\GmailClientFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GmailConnection> */
class GmailConnectionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory()->admin(), 'email' => fake()->safeEmail(), 'access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_at' => now()->addHour(), 'scopes' => [GmailClientFactory::SCOPE], 'status' => 'connected', 'connected_at' => now()];
    }
}
