<?php

namespace Database\Factories;

use App\Enums\PromotionStatus;
use App\Models\Organization;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Promotion> */
class PromotionFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory()->wholesaler(), 'title' => fake()->sentence(3), 'description' => fake()->paragraph(),
            'starts_on' => now('Asia/Dushanbe')->toDateString(), 'ends_on' => now('Asia/Dushanbe')->addWeek()->toDateString(), 'status' => PromotionStatus::Draft];
    }

    public function published(): static
    {
        return $this->state(['status' => PromotionStatus::Published, 'first_published_at' => now()]);
    }
}
