<?php

namespace App\Jobs;

use App\Enums\PriceListImportStatus;
use App\Models\PriceListImport;
use App\Services\PriceList\ImportActivator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class MaterializePriceListImportChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [15, 45, 120];

    public function __construct(
        public readonly int $importId,
        public readonly int $firstRowId,
        public readonly int $lastRowId,
        public readonly ?int $actorId = null,
    ) {}

    public function handle(ImportActivator $activator): void
    {
        $import = PriceListImport::findOrFail($this->importId);
        if (in_array($import->status, [PriceListImportStatus::Completed, PriceListImportStatus::Superseded, PriceListImportStatus::Failed], true)) {
            return;
        }

        $activator->materialize($import, $this->firstRowId, $this->lastRowId, $this->actorId);
    }

    public function failed(?Throwable $exception): void
    {
        $context = [
            'stage' => 'activation_materialization', 'import_id' => $this->importId,
            'first_row_id' => $this->firstRowId, 'last_row_id' => $this->lastRowId,
            'exception' => $exception === null ? null : $exception::class,
            'sqlstate' => $exception?->getCode() ?: null,
            'message' => mb_substr($exception?->getMessage() ?? 'Неизвестная ошибка', 0, 1200),
        ];

        PriceListImport::query()->whereKey($this->importId)
            ->whereIn('status', [PriceListImportStatus::Preview, PriceListImportStatus::Committing])
            ->update([
                'status' => PriceListImportStatus::Failed, 'failure_stage' => 'activation_materialization',
                'failed_at' => now(), 'failure_message' => json_encode($context, JSON_UNESCAPED_UNICODE),
            ]);
    }
}
