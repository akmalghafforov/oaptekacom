<?php

namespace App\Jobs;

use App\Services\ExternalSupplierDirectory;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshExternalSupplierDirectory implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly string $source) {}

    public int $uniqueFor = 140;

    public function uniqueId(): string
    {
        return (string) $this->source;
    }

    public function handle(ExternalSupplierDirectory $directory): void
    {
        $directory->refreshAvailabilityPage($this->source);
    }
}
