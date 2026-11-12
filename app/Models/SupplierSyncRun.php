<?php

namespace App\Models;

use Database\Factories\SupplierSyncRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierSyncRun extends Model
{
    /** @use HasFactory<SupplierSyncRunFactory> */
    use HasFactory;

    protected $guarded = [];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'supplier_organization_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SupplierSyncAttempt::class);
    }

    protected function casts(): array
    {
        return ['decision' => 'array', 'mailbox_wait_started_at' => 'datetime', 'next_retry_at' => 'datetime', 'configuration_snapshot' => 'array', 'lease_expires_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
