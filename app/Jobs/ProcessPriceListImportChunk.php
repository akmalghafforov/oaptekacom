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
        $import = $this->import->fresh();
        if ($import->status !== PriceListImportStatus::Processing) {
            return;
        }
        $processor->process($import, $reader->rows($import, $this->startRow, $this->endRow));
    }

    public function failed(?Throwable $exception): void
    {
        $this->import->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'parsing', 'failed_at' => now(), 'failure_message' => mb_substr($exception?->getMessage() ?? 'Неизвестная ошибка', 0, 2000)]);
    }
}
