<?php

namespace App\Jobs;

use App\Services\ExternalPriceDownload;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DownloadExternalPricePage implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public readonly int $downloadId) {}

    public int $uniqueFor = 140;

    public function uniqueId(): string
    {
        return (string) $this->downloadId;
    }

    public function handle(ExternalPriceDownload $downloads): void
    {
        $downloads->process($this->downloadId);
    }
}
