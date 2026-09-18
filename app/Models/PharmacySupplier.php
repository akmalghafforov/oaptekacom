<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PharmacySupplier extends Model
{
    protected $guarded = [];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }
}
