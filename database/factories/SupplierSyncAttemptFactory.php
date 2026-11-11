<?php

namespace Database\Factories;

use App\Models\SupplierSyncAttempt;
use App\Models\SupplierSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierSyncAttempt> */
class SupplierSyncAttemptFactory extends Factory
{
    public function definition(): array
    {
        return ['supplier_sync_run_id' => SupplierSyncRun::factory(), 'source' => 'pricelist_tj', 'status' => 'fetching'];
    }
}
