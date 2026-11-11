<?php

namespace Database\Factories;

use App\Models\ExternalSupplierPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalSupplierPartner> */
class ExternalSupplierPartnerFactory extends Factory
{
    public function definition(): array
    {
        return ['source' => 'pricelist_tj', 'external_id' => fake()->unique()->uuid(), 'name' => fake()->company(), 'phones' => [], 'discovered_at' => now()];
    }
}
