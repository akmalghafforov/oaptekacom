<?php

namespace App\Models;

use Database\Factories\SupplierProductAliasFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProductAlias extends Model
{
    /** @use HasFactory<SupplierProductAliasFactory> */
    use HasFactory;

    protected $guarded = [];

    public function supplierProduct(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }
}
