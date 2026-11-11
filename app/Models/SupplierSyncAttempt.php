<?php

namespace App\Models;

use Database\Factories\SupplierSyncAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierSyncAttempt extends Model
{
    /** @use HasFactory<SupplierSyncAttemptFactory> */
    use HasFactory;

    protected $guarded = [];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SupplierSyncRun::class, 'supplier_sync_run_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PriceListImport::class, 'price_list_import_id');
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
