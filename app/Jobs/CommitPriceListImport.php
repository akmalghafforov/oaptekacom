<?php

namespace App\Jobs;

use App\Enums\PriceListImportStatus;
use App\Models\PriceListImport;
use App\Models\User;
use App\Services\PriceList\ImportActivator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CommitPriceListImport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 75;

    public array $backoff = [15, 45, 120];

    public function __construct(public readonly PriceListImport $import, public readonly ?int $actorId = null) {}

    public function uniqueId(): string
    {
        return (string) $this->import->id;
    }

    public function handle(ImportActivator $activator): void
    {
        $import = $this->import->fresh();
        if ($import->status !== PriceListImportStatus::Preview) {
            return;
        }

        $activator->activate($import, $this->actorId === null ? null : User::find($this->actorId));
    }

    public function failed(?Throwable $exception): void
    {
        $import = $this->import->fresh();
        if ($import->status === PriceListImportStatus::Completed) {
            return;
        }
        $import->update([
            'status' => PriceListImportStatus::Failed,
            'failure_stage' => 'activation',
            'failed_at' => now(),
            'failure_message' => mb_substr($exception?->getMessage() ?? 'Неизвестная ошибка', 0, 2000),
        ]);
    }
}
