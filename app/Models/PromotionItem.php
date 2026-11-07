<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromotionItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function sourceOffer(): BelongsTo
    {
        return $this->belongsTo(Offer::class, 'source_offer_id');
    }

    public function displayName(): string
    {
        return implode(' · ', array_filter([$this->name, $this->dosage, $this->form, $this->packaging]));
    }

    public function availabilityReason(int $quantity): ?string
    {
        if ($this->trashed() || ! $this->promotion->isActive()) {
            return 'promotion_inactive';
        }
        if ($this->price === null) {
            return 'promotion_quote_only';
        }
        if (! $this->in_stock || $this->stock === 0) {
            return 'out_of_stock';
        }
        if ($quantity < $this->minimum_quantity) {
            return 'promotion_minimum';
        }

        return $this->stock !== null && $quantity > $this->stock ? 'insufficient_stock' : null;
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'in_stock' => 'boolean', 'stock' => 'integer', 'minimum_quantity' => 'integer'];
    }
}
