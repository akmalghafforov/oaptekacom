<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\OneTimePassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PhoneOtpService
{
    public function __construct(private OsonSmsGateway $smsGateway) {}

    public function send(string $purpose, string $phone, UserRole $accountType): bool
    {
        OneTimePassword::where('expires_at', '<', now()->subDay())->delete();
        OneTimePassword::where('purpose', $purpose)->where('account_type', $accountType)->where('phone', $phone)->whereNull('consumed_at')->update(['status' => 'invalidated', 'consumed_at' => now()]);
        $code = (string) random_int(100000, 999999);
        $otp = OneTimePassword::create(['purpose' => $purpose, 'account_type' => $accountType, 'phone' => $phone, 'code_hash' => Hash::make($code), 'transaction_id' => (string) Str::uuid(), 'status' => 'pending', 'expires_at' => now()->addMinutes(5), 'attempts' => 0]);

        try {
            $accountLabel = $accountType === UserRole::Pharmacy ? 'аптеки' : 'поставщика';
            $provider = $this->smsGateway->send($phone, "Код подтверждения OAPTEKA для {$accountLabel}: {$code}. Не сообщайте его никому.", $otp->transaction_id);
            $otp->update(['status' => 'sent', 'sent_at' => now(), 'provider_transaction_id' => $provider['transaction_id'], 'provider_message_id' => $provider['message_id']]);

            return true;
        } catch (\Throwable) {
            $otp->update(['status' => 'failed']);

            return false;
        }
    }

    public function consume(string $purpose, string $phone, UserRole $accountType, string $code): bool
    {
        $otp = OneTimePassword::where('purpose', $purpose)->where('account_type', $accountType)->where('phone', $phone)->where('status', 'sent')->whereNull('consumed_at')->latest('id')->first();
        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= 5) {
            return false;
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return false;
        }
        $otp->update(['status' => 'consumed', 'consumed_at' => now()]);

        return true;
    }
}
