<?php

namespace Database\Factories;

use App\Models\ExternalListingRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalListingRun> */
class ExternalListingRunFactory extends Factory
{
    public function definition(): array
    {
        return ['source' => 'icorp', 'scan_hour' => now()->startOfHour(), 'status' => 'pending', 'pages' => []];
    }
}
