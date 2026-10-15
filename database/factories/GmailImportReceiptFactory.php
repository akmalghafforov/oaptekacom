<?php

namespace Database\Factories;

use App\Models\GmailImportReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GmailImportReceipt> */
class GmailImportReceiptFactory extends Factory
{
    public function definition(): array
    {
        return ['mailbox_email' => fake()->safeEmail(), 'gmail_message_id' => fake()->unique()->regexify('[a-f0-9]{16}'), 'status' => 'pending', 'attempts' => 0];
    }
}
