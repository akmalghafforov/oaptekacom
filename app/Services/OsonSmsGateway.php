<?php

namespace App\Services;

use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Log;
use OsonSMS\OsonSMSService\OsonSmsService;

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

        $messageId = app(OsonSmsService::class)->sendSMS(
            senderName: (string) config('services.oson_sms.sender'),
            phonenumber: PhoneNormalizer::osonRecipient($canonicalPhone),
            message: $message,
            txnId: $transactionId,
        );

        return ['transaction_id' => $transactionId, 'message_id' => (string) $messageId];
    }
}
