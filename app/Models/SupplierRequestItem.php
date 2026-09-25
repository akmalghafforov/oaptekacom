<?php

namespace App\Models;

use Database\Factories\SupplierRequestItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierRequestItem extends Model
{
    /** @use HasFactory<SupplierRequestItemFactory> */
    use HasFactory;

    protected $guarded = [];

    public function supplierRequest(): BelongsTo
    {
        return $this->belongsTo(SupplierRequest::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }
}
