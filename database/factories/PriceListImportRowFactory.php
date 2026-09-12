<?php

namespace Database\Factories;

use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceListImportRow>
 */
class PriceListImportRowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['price_list_import_id' => PriceListImport::factory(), 'source_row' => fake()->unique()->numberBetween(1, 100000), 'raw_values' => [], 'parsed_values' => [], 'disposition' => PriceListRowDisposition::Valid, 'planned_action' => PriceListRowAction::Create, 'errors' => [], 'warnings' => []];
    }
}
