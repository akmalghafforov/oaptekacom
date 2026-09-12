<?php

namespace Database\Factories;

use App\Enums\SupplierFileType;
use App\Models\Organization;
use App\Models\SupplierImportProfile;
use App\Services\PriceList\ProfileValidator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierImportProfile>
 */
class SupplierImportProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['supplier_organization_id' => Organization::factory()->wholesaler(), 'name' => 'Основной профиль', 'file_type' => SupplierFileType::Xlsx, 'is_active' => true, 'configuration' => ProfileValidator::defaults()];
    }
}
