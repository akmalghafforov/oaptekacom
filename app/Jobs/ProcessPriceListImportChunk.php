<?php

namespace App\Jobs;

use App\Enums\PriceListImportStatus;
use App\Models\PriceListImport;
use App\Services\PriceList\ImportProcessor;
use App\Services\PriceList\WorkbookReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessPriceListImportChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly PriceListImport $import, public readonly int $startRow, public readonly int $endRow) {}

    public function handle(WorkbookReader $reader, ImportProcessor $processor): void
    {
        $processor->process($this->import->fresh(), $reader->rows($this->import->fresh(), $this->startRow, $this->endRow));
    }

    public function failed(?Throwable $exception): void
    {
        $this->import->update(['status' => PriceListImportStatus::Failed, 'failed_at' => now(), 'failure_message' => mb_substr($exception?->getMessage() ?? 'Неизвестная ошибка', 0, 2000)]);
    }
}
