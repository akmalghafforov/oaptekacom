<?php

namespace App\Jobs;

use App\Models\GmailConnection;
use App\Services\GmailApiException;
use App\Services\GmailException;
use App\Services\GmailMessageSync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncGmailMessages implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $connectionId)
    {
        $this->onQueue(config('gmail-imports.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(GmailMessageSync $sync): void
    {
        $lock = Cache::store(config('session.block_store'))->lock('gmail-sync:'.$this->connectionId, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $connection = GmailConnection::find($this->connectionId);
            if (! $connection || $connection->next_sync_at?->isFuture()) {
                return;
            }
            $continues = $sync->sync($this->connectionId);
            $connection->refresh()->update(['next_sync_at' => null]);
            if ($continues) {
                self::dispatch($this->connectionId)->delay(2);
            }
        } catch (Throwable $exception) {
            $delay = $this->backoff[max(0, min($this->attempts() - 1, 2))];
            if ($exception instanceof GmailApiException) {
                $delay = max($delay, $exception->retryAfter);
            }
            GmailConnection::whereKey($this->connectionId)->update(['last_sync_error' => 'sync_failed', 'next_sync_at' => now()->addSeconds($delay)]);
            if ($exception instanceof GmailException && $exception->getMessage() !== 'token_refresh_failed') {
                return;
            }
            if ($exception instanceof GmailApiException && ! $exception->retryable()) {
                return;
            }
            $this->release($delay);
        } finally {
            $lock->release();
        }
    }
}
