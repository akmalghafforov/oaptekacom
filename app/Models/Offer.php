<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Offer extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PriceListImport::class, 'price_list_import_id');
    }

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }

    public function importRow(): HasOne
    {
        return $this->hasOne(PriceListImportRow::class);
    }

    public function scopeCurrentCatalog(Builder $query): Builder
    {
        return $query
            ->whereNotNull('price_list_import_id')
            ->whereHas('organization', fn (Builder $query): Builder => $query->whereColumn('organizations.active_price_list_import_id', 'offers.price_list_import_id'));
    }

    public function scopeCurrentAvailable(Builder $query): Builder
    {
        return $query->currentCatalog()
            ->where(fn (Builder $query): Builder => $query->whereNull('quantity')->orWhere('quantity', '>', 0));
    }

    public function isCurrentAvailable(): bool
    {
        return self::query()->whereKey($this->getKey())->currentAvailable()->exists();
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'is_promotion' => 'boolean',
            'is_active' => 'boolean',
            'price' => 'decimal:2',
            'old_price' => 'decimal:2',
            'quantity' => 'decimal:3',
            'total_value' => 'decimal:2',
            'effective_price' => 'decimal:2',
            'applied_supplier_discount_percent' => 'decimal:2',
        ];
    }
}
