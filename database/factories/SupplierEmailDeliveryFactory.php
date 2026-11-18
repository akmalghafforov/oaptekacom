<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SupplierEmailDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierEmailDelivery> */
class SupplierEmailDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return ['supplier_organization_id' => Organization::factory()->wholesaler(), 'mailbox_email' => 'inbox@example.com', 'gmail_message_id' => fake()->unique()->uuid(),
            'received_at' => now(), 'published_day' => now('Asia/Dushanbe')->toDateString(), 'date_origin' => 'arrival', 'date_precision' => 'day', 'manifest' => [['filename' => 'prices.csv']]];
    }
}
