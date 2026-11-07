<?php

namespace Database\Seeders;

use App\Models\Promotion;
use App\Models\PromotionContribution;
use App\Services\PromotionQualification;
use App\Services\PromotionWorkflow;
use Illuminate\Database\Seeder;

class PromotionParticipantSeeder extends Seeder
{
    public function run(): void
    {
        $contribution = PromotionContribution::factory()->create();
        $contribution->order->update(['status' => 'confirmed']);
        $contribution->order->items()->create(['quantity' => 1, 'unit_price' => $contribution->amount, 'snapshot' => ['medicine' => 'Тестовый товар']]);
        app(PromotionWorkflow::class)->locked($contribution->promotion, function (Promotion $promotion) use ($contribution): void {
            app(PromotionQualification::class)->recompute($promotion, $contribution->pharmacy_organization_id);
        });
    }
}
