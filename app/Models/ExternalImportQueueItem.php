<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalImportQueueItem extends Model
{
    protected $guarded = [];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SupplierSyncRun::class, 'supplier_sync_run_id');
    }

    public function download(): BelongsTo
    {
        return $this->belongsTo(ExternalDownload::class, 'external_download_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PriceListImport::class, 'price_list_import_id');
    }

    protected function casts(): array
    {
        return array_fill_keys(['enqueued_at', 'started_at', 'recovery_deadline', 'heartbeat_at', 'worker_expires_at', 'next_retry_at', 'dispatch_requested_at', 'dispatched_at', 'finished_at'], 'datetime');
    }
}
