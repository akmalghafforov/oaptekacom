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
    public function configure(): static
    {
        return $this->afterCreating(function (SupplierImportProfile $profile): void {
            $supplier = $profile->supplier;
            $supplier->newQuery()->whereKey($supplier->id)->whereNull('inbox_import_profile_id')->update(['inbox_import_profile_id' => $profile->id]);
            if ($profile->is_active && $profile->manual_enabled) {
                $supplier->newQuery()->whereKey($supplier->id)->whereNull('manual_import_profile_id')->update(['manual_import_profile_id' => $profile->id]);
            }
            $supplier->refresh();
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['supplier_organization_id' => Organization::factory()->wholesaler(), 'name' => 'Основной профиль', 'file_type' => SupplierFileType::Xlsx, 'is_active' => true, 'inbox_enabled' => true, 'manual_enabled' => true, 'configuration' => ProfileValidator::defaults()];
    }
}
