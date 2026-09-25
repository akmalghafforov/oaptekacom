<?php

namespace App\Models;

use Database\Factories\SupplierRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierRequest extends Model
{
    /** @use HasFactory<SupplierRequestFactory> */
    use HasFactory;

    protected $guarded = [];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierRequestItem::class);
    }

    protected function casts(): array
    {
        return ['shared_at' => 'datetime', 'total' => 'decimal:2'];
    }
}
