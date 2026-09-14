<?php

namespace App\Models;

use App\Enums\PriceListImportSource;
use App\Enums\PriceListImportStatus;
use Database\Factories\PriceListImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceListImport extends Model
{
    /** @use HasFactory<PriceListImportFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (PriceListImport $import): void {
            if ($import->offers()->exists()) {
                throw new \LogicException('Импорт с материализованными предложениями нельзя удалить.');
            }
        });
    }

    protected $guarded = [];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(SupplierImportProfile::class, 'supplier_import_profile_id');
    }

    public function categoryRuleSet(): BelongsTo
    {
        return $this->belongsTo(ProductCategoryRuleSet::class, 'product_category_rule_set_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(PriceListImportRow::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_import_id');
    }

    protected function casts(): array
    {
        return [
            'status' => PriceListImportStatus::class, 'source_type' => PriceListImportSource::class,
            'profile_snapshot' => 'array', 'effective_layout' => 'array', 'summary' => 'array', 'received_at' => 'datetime',
            'inventory_at' => 'datetime', 'processing_started_at' => 'datetime', 'previewed_at' => 'datetime',
            'activated_at' => 'datetime', 'failed_at' => 'datetime', 'superseded_at' => 'datetime',
            'duplicate_confirmed_at' => 'datetime',
        ];
    }
}
