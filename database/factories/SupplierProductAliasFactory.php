<?php

namespace Database\Factories;

use App\Models\SupplierProduct;
use App\Models\SupplierProductAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierProductAlias>
 */
class SupplierProductAliasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = SupplierProduct::factory();

        return ['supplier_product_id' => $product, 'supplier_organization_id' => fn (array $attributes) => SupplierProduct::find($attributes['supplier_product_id'])->supplier_organization_id, 'normalized_name' => mb_strtolower(fake()->unique()->words(3, true))];
    }
}
