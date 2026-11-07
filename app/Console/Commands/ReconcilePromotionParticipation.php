<?php

namespace App\Console\Commands;

use App\Models\Promotion;
use App\Services\PromotionQualification;
use App\Services\PromotionWorkflow;
use Illuminate\Console\Command;

class ReconcilePromotionParticipation extends Command
{
    protected $signature = 'promotions:reconcile-participation {--promotion= : Reconcile one promotion ID}';

    protected $description = 'Rebuild prize eligibility from captured order contributions without importing historical orders';

    public function handle(PromotionQualification $qualification, PromotionWorkflow $workflow): int
    {
        if ($this->option('promotion') !== null && (! ctype_digit((string) $this->option('promotion')) || (int) $this->option('promotion') < 1)) {
            $this->error('Укажите положительный ID акции.');

            return self::FAILURE;
        }
        $query = Promotion::query()->whereNotNull('purchase_scope')
            ->when($this->option('promotion'), fn ($query, $id) => $query->whereKey($id));
        foreach ($query->lazyById(100) as $promotion) {
            $workflow->locked($promotion, function (Promotion $current) use ($qualification): void {
                $pharmacyIds = $current->contributions()->distinct()->pluck('pharmacy_organization_id')
                    ->merge($current->participants()->pluck('pharmacy_organization_id'))->unique()->sort();
                foreach ($pharmacyIds as $pharmacyId) {
                    $qualification->recompute($current, (int) $pharmacyId);
                }
            });
        }
        $this->info('Участия пересчитаны.');

        return self::SUCCESS;
    }
}
