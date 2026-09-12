<?php

namespace App\Models;

use App\Enums\OrganizationType;
use App\Enums\TradeMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Organization $organization): void {
            if ($organization->priceListImports()->exists() || $organization->supplierProducts()->exists()) {
                throw new \LogicException('Поставщика с историей импортов нельзя удалить.');
            }
        });
    }

    protected $guarded = [];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function priceListImports(): HasMany
    {
        return $this->hasMany(PriceListImport::class, 'supplier_organization_id');
    }

    public function importProfile(): HasOne
    {
        return $this->hasOne(SupplierImportProfile::class, 'supplier_organization_id');
    }

    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class, 'supplier_organization_id');
    }

    public function senderAddresses(): HasMany
    {
        return $this->hasMany(SupplierSenderAddress::class, 'supplier_organization_id');
    }

    public function activePriceListImport(): BelongsTo
    {
        return $this->belongsTo(PriceListImport::class, 'active_price_list_import_id');
    }

    public function permitsMode(TradeMode $mode): bool
    {
        return $this->type === OrganizationType::Pharmacy || $this->supplier_mode === 'both' || $this->supplier_mode === $mode->value;
    }

    protected function casts(): array
    {
        return ['subscription_until' => 'datetime', 'type' => OrganizationType::class];
    }
}
