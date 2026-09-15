<?php

namespace App\Jobs;

use App\Services\PriceList\ImportActivator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupSupersededPriceListOffers implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300, 600];

    public function __construct(public readonly int $activeImportId, public readonly ?int $supersededImportId) {}

    public function handle(ImportActivator $activator): void
    {
        $activator->cleanup($this->activeImportId, $this->supersededImportId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Price-list legacy offer cleanup failed.', [
            'stage' => 'activation_cleanup', 'active_import_id' => $this->activeImportId,
            'superseded_import_id' => $this->supersededImportId, 'exception' => $exception,
        ]);
    }
}
