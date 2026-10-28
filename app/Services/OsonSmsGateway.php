<?php

namespace App\Services;

use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OsonSMS\OsonSMSService\Models\OsonSMSLog;

class OsonSmsGateway
{
    /** @return array{transaction_id: ?string, message_id: ?string} */
    public function send(string $canonicalPhone, string $message, string $transactionId): array
    {
        if (config('services.oson_sms.driver') === 'dummy') {
            Log::notice('Dummy SMS intercepted.', [
                'phone' => $canonicalPhone,
                'message' => $message,
                'transaction_id' => $transactionId,
            ]);

            return ['transaction_id' => $transactionId, 'message_id' => null];
        }

        $response = Http::withToken((string) config('osonsmsservice.bearer_token'))
            ->connectTimeout(3)
            ->timeout(10)
            ->withoutRedirecting()
            ->get(rtrim((string) config('osonsmsservice.server_url'), '/').'/sendsms_v1.php', [
                'from' => (string) config('services.oson_sms.sender'),
                'phone_number' => PhoneNormalizer::osonRecipient($canonicalPhone),
                'msg' => $message,
                'txn_id' => $transactionId,
                'login' => (string) config('osonsmsservice.login'),
            ]);
        $log = new OsonSMSLog;
        $log->forceFill([
            'login' => (string) config('osonsmsservice.login'),
            'sender_name' => (string) config('services.oson_sms.sender'),
            'message' => $message,
            'phonenumber' => PhoneNormalizer::osonRecipient($canonicalPhone),
            'server_response' => $response->successful() ? $response->body() : $response->json('error.msg', $response->body()),
            'msgid' => $response->json('msg_id'),
            'is_sent' => $response->successful() && is_numeric($response->json('msg_id')) ? 1 : 0,
        ])->save();
        if (! $response->successful()) {
            throw new \RuntimeException('SMS gateway rejected delivery.');
        }
        $messageId = $response->json('msg_id');
        if (! is_numeric($messageId)) {
            throw new \RuntimeException('SMS gateway did not confirm delivery.');
        }

        return ['transaction_id' => $transactionId, 'message_id' => (string) $messageId];
    }
}
