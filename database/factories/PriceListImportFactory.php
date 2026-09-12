<?php

namespace Database\Factories;

use App\Enums\PriceListImportSource;
use App\Enums\PriceListImportStatus;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierImportProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceListImport>
 */
class PriceListImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $supplier = Organization::factory()->wholesaler();

        return ['supplier_organization_id' => $supplier, 'supplier_import_profile_id' => SupplierImportProfile::factory()->for($supplier, 'supplier'), 'profile_snapshot' => [], 'source_type' => PriceListImportSource::Manual, 'file_path' => 'price-list-imports/test.xlsx', 'original_filename' => 'test.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'file_size' => 1, 'sha256' => fake()->unique()->sha256(), 'status' => PriceListImportStatus::Pending, 'summary' => []];
    }
}
