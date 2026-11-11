<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SupplierSynchronization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierSynchronization> */
class SupplierSynchronizationFactory extends Factory
{
    public function definition(): array
    {
        return ['supplier_organization_id' => Organization::factory()->wholesaler(), 'sources' => SupplierSynchronization::defaultSources(), 'automatic_enabled' => false, 'paused' => false];
    }
}
