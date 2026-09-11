<?php

namespace App\Models;

use App\Enums\SubscriptionPlan;
use Database\Factories\SubscriptionPlanPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['plan', 'daily_price'])]
class SubscriptionPlanPrice extends Model
{
    /** @use HasFactory<SubscriptionPlanPriceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['plan' => SubscriptionPlan::class, 'daily_price' => 'decimal:2'];
    }
}
