<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionContribution extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'pharmacy_organization_id');
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
