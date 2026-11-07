<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionParticipant extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'pharmacy_organization_id');
    }

    protected function casts(): array
    {
        return ['qualified_at' => 'datetime', 'is_eligible' => 'boolean', 'qualifying_total' => 'decimal:2', 'largest_order_value' => 'decimal:2', 'qualifying_order_count' => 'integer'];
    }
}
