<?php

namespace Database\Factories;

use App\Models\Medicine;
use App\Models\Offer;
use App\Models\SupplierRequest;
use App\Models\SupplierRequestItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierRequestItem>
 */
class SupplierRequestItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 10);
        $unitPrice = fake()->randomFloat(2, 1, 100);

        return [
            'supplier_request_id' => SupplierRequest::factory(),
            'offer_id' => Offer::factory(),
            'medicine_id' => Medicine::factory(),
            'product_name' => fake()->words(3, true),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
            'snapshot' => [],
        ];
    }
}
