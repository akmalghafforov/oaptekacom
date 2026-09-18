<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvitation extends Model
{
    protected $guarded = [];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'redeemed_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
