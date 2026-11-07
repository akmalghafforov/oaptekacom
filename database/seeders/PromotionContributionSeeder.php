<?php

namespace Database\Seeders;

use App\Models\PromotionContribution;
use Illuminate\Database\Seeder;

class PromotionContributionSeeder extends Seeder
{
    public function run(): void
    {
        PromotionContribution::factory()->create();
    }
}
