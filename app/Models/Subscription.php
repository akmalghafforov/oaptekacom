<?php

namespace App\Models;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTerm;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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

    protected function casts(): array
    {
        return ['plan' => SubscriptionPlan::class, 'term' => SubscriptionTerm::class, 'status' => SubscriptionStatus::class, 'starts_on' => 'date', 'ends_on' => 'date', 'actual_ended_at' => 'datetime', 'daily_price' => 'decimal:2', 'total_price' => 'decimal:2'];
    }
}
