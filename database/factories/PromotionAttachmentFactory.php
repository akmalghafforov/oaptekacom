<?php

namespace Database\Factories;

use App\Models\Promotion;
use App\Models\PromotionAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromotionAttachment> */
class PromotionAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return ['promotion_id' => Promotion::factory(), 'path' => 'promotions/example.pdf', 'original_name' => 'brochure.pdf', 'mime_type' => 'application/pdf', 'size' => 100, 'role' => 'material'];
    }
}
