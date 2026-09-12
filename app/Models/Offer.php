<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends Model
{
    protected $guarded = [];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'is_promotion' => 'boolean',
            'is_active' => 'boolean',
            'price' => 'decimal:2',
            'old_price' => 'decimal:2',
        ];
    }
}
