<?php

namespace App\Jobs;

use App\Models\SupplierReview;
use App\Services\TelegramSupplierReviews;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeliverSupplierReview implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 45;

    public function __construct(public int $reviewId) {}

    public function handle(TelegramSupplierReviews $service): void
    {
        if (! $service->configured()) {
            return;
        }
        $lock = Cache::lock('telegram-review:'.$this->reviewId, 120);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }
        try {
            $this->deliver($service);
        } finally {
            $lock->release();
        }
    }

    private function deliver(TelegramSupplierReviews $service): void
    {
        $review = SupplierReview::find($this->reviewId);
        if (! $review || in_array($review->delivery_state, ['sending', 'ambiguous', 'failed'])
            || ($review->message_id && $review->delivered_version >= $review->version)
            || $review->next_attempt_at?->isFuture()) {
            return;
        }
        $sending = ! $review->message_id;
        $version = $review->version;
        $payload = $service->message($review);
        $payload['chat_id'] = $review->chat_id ?? (string) config('telegram.group_chat_id');
        if (! $sending) {
            $payload['message_id'] = $review->message_id;
        }
        $attempts = $review->attempts + 1;
        SupplierReview::whereKey($review->id)->update(['delivery_state' => $sending ? 'sending' : 'editing', 'attempts' => $attempts, 'chat_id' => $payload['chat_id'], 'updated_at' => now()]);
        try {
            $response = Http::connectTimeout(5)->timeout(20)->post('https://api.telegram.org/bot'.config('telegram.bot_token').'/'.($sending ? 'sendMessage' : 'editMessageText'), $payload);
        } catch (\Throwable) {
            $this->failure($review, $sending ? 'ambiguous' : 'retry', 'transport_failure', $attempts);

            return;
        }
        $notModified = ! $sending && str_contains((string) $response->json('description'), 'message is not modified');
        if (($response->successful() && $response->json('ok') === true) || $notModified) {
            $messageId = $sending ? $response->json('result.message_id') : $review->message_id;
            $chatId = $sending ? $response->json('result.chat.id') : $review->chat_id;
            if ((! is_int($messageId) && ! is_string($messageId)) || ! preg_match('/^[1-9]\d*$/D', (string) $messageId)
                || (string) $chatId !== (string) $payload['chat_id']) {
                $this->failure($review, $sending ? 'ambiguous' : 'retry', 'invalid_response', $attempts);

                return;
            }
            SupplierReview::whereKey($review->id)->update(['message_id' => (string) $messageId, 'delivery_state' => 'sent', 'delivered_version' => $version, 'attempts' => 0, 'next_attempt_at' => null, 'last_error' => null]);

            return;
        }
        $code = (int) ($response->json('error_code') ?? $response->status());
        $state = $code === 429 ? 'retry' : ($code >= 500 ? ($sending ? 'ambiguous' : 'retry') : 'failed');
        $retryAfter = $response->json('parameters.retry_after');
        $this->failure($review, $state, 'telegram_'.$code, $attempts, is_numeric($retryAfter) ? (int) $retryAfter : 0);
    }

    private function failure(SupplierReview $review, string $state, string $error, int $attempts, int $retryAfter = 0): void
    {
        if ($state === 'retry' && $attempts >= 5) {
            $state = 'failed';
        }
        SupplierReview::whereKey($review->id)->update(['delivery_state' => $state, 'last_error' => $error,
            'next_attempt_at' => $state === 'retry' ? now()->addSeconds(max(min($retryAfter, 86400), min(3600, 30 * (2 ** ($attempts - 1))))) : null]);
        if (in_array($state, ['failed', 'ambiguous'])) {
            Log::warning('Telegram supplier review requires operator attention', ['review_id' => $review->id, 'state' => $state, 'error' => $error]);
        }
    }
}
