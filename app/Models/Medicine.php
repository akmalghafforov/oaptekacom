<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medicine extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Medicine $medicine): void {
            if ($medicine->supplierProducts()->exists() || $medicine->offers()->whereNotNull('price_list_import_id')->exists()) {
                throw new \LogicException('Товар с историей импортов нельзя удалить.');
            }
        });
    }

    protected $guarded = [];

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }

    public function categoryRuleSet(): BelongsTo
    {
        return $this->belongsTo(ProductCategoryRuleSet::class, 'category_rule_set_id');
    }

    protected function casts(): array
    {
        return ['category_evidence' => 'array', 'category_assigned_at' => 'datetime'];
    }
}
