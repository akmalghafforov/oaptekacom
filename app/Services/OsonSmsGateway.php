<?php

namespace App\Services;

use App\Support\PhoneNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class OsonSmsGateway
{
    /** @return array{transaction_id: ?string, message_id: ?string} */
    public function send(string $canonicalPhone, string $message, string $transactionId): array
    {
        $response = Http::withToken((string) config('services.oson_sms.token'))->connectTimeout(3)->timeout(8)->retry(2, 200, fn (Throwable $exception): bool => $exception instanceof ConnectionException)->get('https://api.osonsms.com/sendsms_v1.php', ['login' => config('services.oson_sms.login'), 'sender' => config('services.oson_sms.sender'), 'phone_number' => PhoneNormalizer::osonRecipient($canonicalPhone), 'message' => $message, 'transaction_id' => $transactionId, 'is_confidential' => 'true']);

        if (! $response->successful()) {
            throw new \RuntimeException('OSON SMS is unavailable.');
        }

        $payload = $response->json();
        if (is_array($payload) && isset($payload['status']) && ! in_array(strtolower((string) $payload['status']), ['ok', 'success', 'accepted'], true)) {
            throw new \RuntimeException('OSON SMS rejected the message.');
        }

        return ['transaction_id' => is_array($payload) ? ($payload['transaction_id'] ?? $payload['id'] ?? null) : null, 'message_id' => is_array($payload) ? ($payload['message_id'] ?? null) : null];
    }
}
