<?php

namespace App\Models;

use App\Enums\OrganizationType;
use App\Enums\TradeMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::updated(function (Organization $organization): void {
            if ($organization->type === OrganizationType::Wholesaler && $organization->wasChanged('status') && $organization->status === 'active') {
                $linkedPharmacyId = $organization->fresh()->linked_pharmacy_organization_id;
                if ($linkedPharmacyId) {
                    Organization::query()->whereKey($linkedPharmacyId)
                        ->where('status', 'pending')->update(['status' => 'active', 'setup_required' => false]);
                }
            }
        });
        static::deleting(function (Organization $organization): void {
            if ($organization->priceListImports()->exists() || $organization->supplierProducts()->exists()) {
                throw new \LogicException('Поставщика с историей импортов нельзя удалить.');
            }
        });
    }

    protected $guarded = [];

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_role_assignments');
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleAssignment::class);
    }

    public function linkedPharmacyOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'linked_pharmacy_organization_id');
    }

    public function sourceSupplierOrganization(): HasOne
    {
        return $this->hasOne(Organization::class, 'linked_pharmacy_organization_id');
    }

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

    public function pharmacySupplierDiscounts(): HasMany
    {
        return $this->hasMany(PharmacySupplierDiscount::class, 'pharmacy_organization_id');
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
        return ['subscription_until' => 'datetime', 'type' => OrganizationType::class, 'additional_phones' => 'array', 'setup_required' => 'boolean'];
    }
}
