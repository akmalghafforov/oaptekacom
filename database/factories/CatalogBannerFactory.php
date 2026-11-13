<?php

namespace Database\Factories;

use App\Models\CatalogBanner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CatalogBanner> */
class CatalogBannerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true), 'title' => fake()->sentence(), 'description' => fake()->paragraph(),
            'whatsapp_number' => '992900000000', 'whatsapp_message' => null,
            'desktop_image' => 'catalog-banners/desktop.jpg', 'tablet_image' => 'catalog-banners/tablet.jpg', 'mobile_image' => 'catalog-banners/mobile.jpg',
            'duration_seconds' => 8, 'is_active' => true,
        ];
    }
}
