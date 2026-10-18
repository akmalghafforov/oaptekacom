<?php

namespace App\Jobs;

use App\Models\GmailConnection;
use App\Services\GmailApiException;
use App\Services\GmailException;
use App\Services\GmailImportDiscovery;
use App\Services\GmailMailboxAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncGmailImports implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    public array $backoff = [30, 120, 300];

    public function __construct()
    {
        $this->onQueue(config('gmail-imports.queue'));
    }

    public function handle(GmailImportDiscovery $discovery, GmailMailboxAccess $access): void
    {
        if (! config('gmail-imports.enabled')) {
            return;
        }
        $lock = Cache::store(config('session.block_store'))->lock('gmail-discovery:'.config('gmail-imports.connection_id'), 120);
        if (! $lock->get()) {
            return;
        }
        try {
            $connection = $access->connection();
            if ($connection->next_sync_at?->isFuture()) {
                return;
            }
            $discovery->sync();
            $connection->refresh()->update(['next_sync_at' => null]);
            $connection = $access->connection();
            if ($connection->scan_page) {
                self::dispatch()->delay(2);
            }
        } catch (Throwable $exception) {
            $delay = max($this->backoff[min($this->attempts() - 1, 2)], $exception instanceof GmailApiException ? $exception->retryAfter : 0);
            GmailConnection::whereKey(config('gmail-imports.connection_id'))->update(['last_sync_error' => 'sync_failed', 'next_sync_at' => now()->addSeconds($delay)]);
            if ($exception instanceof GmailException && $exception->getMessage() !== 'token_refresh_failed') {
                return;
            }
            if ($exception instanceof GmailApiException) {
                if (! $exception->retryable()) {
                    return;
                }
                if ($exception->retryAfter > ($this->backoff[min($this->attempts() - 1, 2)] ?? 30) && $this->attempts() < $this->tries) {
                    $this->release($exception->retryAfter);

                    return;
                }
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
