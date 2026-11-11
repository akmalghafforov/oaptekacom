<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\SupplierSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierSyncRun> */
class SupplierSyncRunFactory extends Factory
{
    public function definition(): array
    {
        return ['supplier_organization_id' => Organization::factory()->wholesaler(), 'trigger' => 'manual', 'configuration_snapshot' => ['version' => 1], 'status' => 'queued'];
    }
}
