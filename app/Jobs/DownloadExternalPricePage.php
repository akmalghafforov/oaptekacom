<?php

namespace App\Jobs;

use App\Services\ExternalPriceDownload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DownloadExternalPricePage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public readonly int $downloadId) {}

    public function handle(ExternalPriceDownload $downloads): void
    {
        $downloads->process($this->downloadId);
    }
}
