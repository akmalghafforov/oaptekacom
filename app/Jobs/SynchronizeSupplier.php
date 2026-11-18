<?php

namespace App\Jobs;

use App\Models\SupplierSyncRun;
use App\Services\ExternalImportQueue;
use App\Services\SupplierSynchronizationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SynchronizeSupplier implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public readonly int $generation;

    public function __construct(public readonly int $runId, ?int $generation = null)
    {
        $this->generation = $generation ?? app(ExternalImportQueue::class)->generation($this->runId);
    }

    public function handle(SupplierSynchronizationService $service): void
    {
        $run = SupplierSyncRun::find($this->runId);
        if ($run && ($run->active_supplier_id || $run->execution_queue_item_id)) {
            $service->process($run, ($this->generation ?? 1));
        }
    }
}
