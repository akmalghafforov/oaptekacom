<?php

namespace App\Jobs;

use App\Enums\PriceListImportStatus;
use App\Models\PriceListImport;
use App\Models\User;
use App\Services\PriceList\ImportActivator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class CommitPriceListImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [15, 45, 120];

    public function __construct(public readonly int $importId, public readonly ?int $actorId = null) {}

    public int $maxExceptions = 3;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function middleware(): array
    {
        if (config('queue.connections.'.($this->connection ?? config('queue.default')).'.driver') === 'sync') {
            return [];
        }

        return [(new WithoutOverlapping('price-list-stage:'.$this->importId))->shared()->releaseAfter(30)->expireAfter(140)];
    }

    public function handle(ImportActivator $activator): void
    {
        $import = PriceListImport::findOrFail($this->importId);
        if (in_array($import->status, [PriceListImportStatus::Superseded, PriceListImportStatus::Failed], true)) {
            return;
        }

        $result = $activator->commit($import, $this->actorId === null ? null : User::find($this->actorId));
        CleanupSupersededPriceListOffers::dispatch($result['import']->id, $result['superseded_import_id'])
            ->onQueue(config('price-list-imports.queue'));
    }

    public function failed(?Throwable $exception): void
    {
        $context = [
            'stage' => 'activation_cutover', 'import_id' => $this->importId,
            'exception' => $exception === null ? null : $exception::class,
            'sqlstate' => $exception?->getCode() ?: null,
            'message' => mb_substr($exception?->getMessage() ?? 'Неизвестная ошибка', 0, 1200),
        ];

        PriceListImport::query()->whereKey($this->importId)
            ->whereIn('status', [PriceListImportStatus::Preview, PriceListImportStatus::Committing])
            ->update([
                'status' => PriceListImportStatus::Failed, 'failure_stage' => 'activation_cutover',
                'failed_at' => now(), 'failure_message' => json_encode($context, JSON_UNESCAPED_UNICODE),
            ]);
    }
}
