<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return ['buyer_organization_id' => Organization::factory()->pharmacy(), 'supplier_organization_id' => Organization::factory()->wholesaler(), 'status' => 'new', 'total' => '100.00'];
    }
}
