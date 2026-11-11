<?php

namespace App\Jobs;

use App\Services\ExternalSupplierDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshExternalSupplierDirectory implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly string $source) {}

    public function handle(ExternalSupplierDirectory $directory): void
    {
        $directory->refresh($this->source);
    }
}
