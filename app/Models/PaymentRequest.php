<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'user_id', 'days', 'amount', 'payment_method', 'recipient_wallet', 'payment_instructions', 'receipt_path', 'transfer_reference', 'transferred_on', 'status', 'verified_amount', 'verified_reference', 'reviewed_by', 'reviewed_at', 'rejection_reason'])]
class PaymentRequest extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    protected function casts(): array
    {
        return ['payment_method' => PaymentMethod::class, 'amount' => 'decimal:2', 'verified_amount' => 'decimal:2', 'transferred_on' => 'date', 'reviewed_at' => 'datetime'];
    }
}
