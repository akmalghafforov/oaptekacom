<?php

namespace App\Jobs;

use App\Models\GmailImportReceipt;
use App\Services\GmailApiException;
use App\Services\GmailException;
use App\Services\GmailImportIntake;
use Google\Service\Exception as GoogleException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProcessGmailImportReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $receiptId)
    {
        $this->onQueue(config('gmail-imports.queue'));
    }

    public function handle(GmailImportIntake $intake): void
    {
        if (! config('gmail-imports.enabled')) {
            return;
        }
        $lock = Cache::store(config('session.block_store'))->lock('gmail-receipt:'.$this->receiptId, 120);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }
        try {
            $receipt = GmailImportReceipt::findOrFail($this->receiptId);
            if ($receipt->status !== 'pending') {
                return;
            }
            if ($receipt->next_attempt_at?->isFuture()) {
                return;
            }
            if ($receipt->attempts >= $this->tries) {
                $receipt->update(['status' => 'failed', 'reason' => 'intake_failed']);

                return;
            }
            $receipt->increment('attempts');
            $receipt->update(['next_attempt_at' => null]);
            try {
                $intake->process($receipt);
            } catch (GoogleException $exception) {
                $reasons = array_column($exception->getErrors() ?? [], 'reason');
                if (in_array($exception->getCode(), [429, 500, 502, 503, 504], true) || ($exception->getCode() === 403 && array_intersect($reasons, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'backendError']))) {
                    throw $exception;
                }
                $receipt->update(['status' => 'skipped', 'reason' => $exception->getCode() === 404 ? 'message_unavailable' : 'permission_denied']);
            } catch (GmailException $exception) {
                if ($exception->getMessage() === 'token_refresh_failed') {
                    throw $exception;
                }
                $receipt->decrement('attempts');
                $receipt->update(['reason' => 'reconnect_required']);
            }
        } catch (Throwable $exception) {
            if (isset($receipt) && $receipt->status === 'pending') {
                $delay = max($this->backoff[min(max(0, $receipt->attempts - 1), 2)], $exception instanceof GmailApiException ? $exception->retryAfter : 0);
                $receipt->update(['next_attempt_at' => now()->addSeconds($delay)]);
                if ($receipt->attempts >= $this->tries) {
                    $receipt->update(['status' => 'failed', 'reason' => 'intake_failed']);
                } elseif ($exception instanceof GmailApiException && $exception->retryAfter > $this->backoff[min(max(0, $receipt->attempts - 1), 2)]) {
                    $this->release($delay);

                    return;
                }
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $lock = Cache::store(config('session.block_store'))->lock('gmail-receipt:'.$this->receiptId, 120);
        if (! $lock->get()) {
            return;
        }
        try {
            GmailImportReceipt::whereKey($this->receiptId)->where('status', 'pending')->where('attempts', '>=', $this->tries)->update(['status' => 'failed', 'reason' => 'intake_failed']);
        } finally {
            $lock->release();
        }
    }
}
