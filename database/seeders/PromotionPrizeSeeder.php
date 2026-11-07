<?php

namespace Database\Seeders;

use App\Models\Promotion;
use Illuminate\Database\Seeder;

class PromotionPrizeSeeder extends Seeder
{
    public function run(): void
    {
        Promotion::factory()->withParticipation()->create();
    }
}
