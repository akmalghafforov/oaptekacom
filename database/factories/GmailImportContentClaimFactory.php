<?php

namespace Database\Factories;

use App\Models\GmailImportContentClaim;
use App\Models\PriceListImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GmailImportContentClaim> */
class GmailImportContentClaimFactory extends Factory
{
    public function definition(): array
    {
        return [
            'price_list_import_id' => PriceListImport::factory(),
            'supplier_organization_id' => fn (array $attributes): int => PriceListImport::findOrFail($attributes['price_list_import_id'])->supplier_organization_id,
            'sha256' => fn (array $attributes): string => PriceListImport::findOrFail($attributes['price_list_import_id'])->sha256,
        ];
    }
}
