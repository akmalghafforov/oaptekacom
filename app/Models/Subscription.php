<?php

namespace App\Models;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTerm;
use Carbon\CarbonInterface;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'payment_request_id', 'assigned_by', 'plan', 'term', 'starts_on', 'ends_on', 'daily_price', 'total_price', 'status', 'actual_ended_at'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    #[Scope]
    protected function currentPaid(Builder $query, ?CarbonInterface $today = null): void
    {
        $date = ($today ?? now('Asia/Dushanbe'))->toDateString();

        $query->where('subscriptions.status', SubscriptionStatus::Active)
            ->whereIn('subscriptions.plan', [SubscriptionPlan::Base, SubscriptionPlan::Premium])
            ->whereDate('subscriptions.starts_on', '<=', $date)
            ->whereDate('subscriptions.ends_on', '>=', $date);
    }

    #[Scope]
    protected function effectiveStatus(Builder $query, SubscriptionStatus $status, CarbonInterface $today): void
    {
        if ($status === SubscriptionStatus::Expired) {
            $query->where(function (Builder $query) use ($today): void {
                $query->where('subscriptions.status', SubscriptionStatus::Expired)
                    ->orWhere(function (Builder $query) use ($today): void {
                        $query->where('subscriptions.status', SubscriptionStatus::Active)
                            ->whereDate('subscriptions.ends_on', '<', $today->toDateString());
                    });
            });
        } elseif ($status === SubscriptionStatus::Active) {
            $query->currentPaid($today);
        } else {
            $query->where('subscriptions.status', $status);
        }
    }

    public function effectiveStatusOn(CarbonInterface $today): SubscriptionStatus
    {
        if ($this->status === SubscriptionStatus::Active && $this->ends_on !== null
            && $this->ends_on->toDateString() < $today->toDateString()) {
            return SubscriptionStatus::Expired;
        }

        return $this->status;
    }

    protected function casts(): array
    {
        return ['plan' => SubscriptionPlan::class, 'term' => SubscriptionTerm::class, 'status' => SubscriptionStatus::class, 'starts_on' => 'date', 'ends_on' => 'date', 'actual_ended_at' => 'datetime', 'daily_price' => 'decimal:2', 'total_price' => 'decimal:2'];
    }
}
