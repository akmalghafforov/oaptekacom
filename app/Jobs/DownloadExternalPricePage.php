<?php

namespace App\Jobs;

use App\Models\ExternalDownload;
use App\Models\SupplierSyncAttempt;
use App\Services\ExternalImportQueue;
use App\Services\ExternalPriceDownload;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DownloadExternalPricePage implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public readonly int $generation;

    public function __construct(public readonly int $downloadId, ?int $generation = null)
    {
        $this->generation = $generation ?? app(ExternalImportQueue::class)->generation(SupplierSyncAttempt::findOrFail(ExternalDownload::findOrFail($this->downloadId)->supplier_sync_attempt_id)->supplier_sync_run_id);
    }

    public int $uniqueFor = 140;

    public function uniqueId(): string
    {
        return $this->downloadId.':'.($this->generation ?? 1);
    }

    public function handle(ExternalPriceDownload $downloads): void
    {
        $downloads->process($this->downloadId, ($this->generation ?? 1));
    }
}
