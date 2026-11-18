<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierVersionClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierVersionClaim> */
class SupplierVersionClaimFactory extends Factory
{
    public function definition(): array
    {
        return ['supplier_organization_id' => Organization::factory()->wholesaler(), 'published_day' => now('Asia/Dushanbe')->toDateString(), 'source' => 'email', 'state' => 'reserved'];
    }

    public function completed(PriceListImport $import): static
    {
        return $this->state(fn (): array => ['supplier_organization_id' => $import->supplier_organization_id,
            'published_day' => ($import->source_published_at ?? now())->copy()->timezone('Asia/Dushanbe')->toDateString(),
            'source' => $import->source_type->value, 'state' => 'completed', 'price_list_import_id' => $import->id, 'completed_at' => $import->activated_at ?? now()]);
    }
}
