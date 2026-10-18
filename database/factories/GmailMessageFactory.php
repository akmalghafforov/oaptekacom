<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class GmailMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->admin(), 'mailbox_email' => fake()->safeEmail(), 'gmail_message_id' => fake()->unique()->uuid(), 'received_at' => now(), 'sender' => fake()->safeEmail(), 'subject' => fake()->sentence(), 'snippet' => '', 'unread' => true, 'attachment_manifest' => [['part_id' => '1', 'name' => 'prices.csv', 'size' => 12]], 'plain_body' => '', 'html_body' => '', 'synced_at' => now()];
    }
}
