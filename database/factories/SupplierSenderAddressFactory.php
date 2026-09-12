<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SupplierSenderAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierSenderAddress>
 */
class SupplierSenderAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return ['supplier_organization_id' => Organization::factory()->wholesaler(), 'email' => $email, 'normalized_email' => mb_strtolower($email)];
    }
}
