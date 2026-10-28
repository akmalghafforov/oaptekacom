<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\OneTimePassword;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PhoneOtpService
{
    public function __construct(private OsonSmsGateway $smsGateway) {}

    public function send(string $purpose, string $phone, UserRole $accountType): bool
    {
        $phone = $this->normalize($phone);
        $lock = Cache::lock('otp:lock:'.hash('sha256', $phone), 60);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['phone' => 'Код уже отправляется. Попробуйте позже.']);
        }

        try {
            $state = $this->state($phone);
            if ($state['send_count'] >= 3) {
                throw ValidationException::withMessages(['phone' => 'Лимит отправки кодов исчерпан. Свяжитесь с нами в WhatsApp или попробуйте позже.']);
            }
            if ($state['resend_available_at'] > $state['server_time']) {
                throw ValidationException::withMessages(['phone' => 'Попробуйте отправить код позже.']);
            }

            return $this->deliver($purpose, $phone, $accountType, $state['send_count']);
        } finally {
            $lock->release();
        }
    }

    /** @return array{send_count: int, resend_available_at: ?int, allowance_resets_at: ?int, server_time: int} */
    public function state(string $phone): array
    {
        $state = Cache::get('otp:allowance:'.hash('sha256', $this->normalize($phone)));
        $time = now()->getTimestampMs();
        if (! is_array($state) || $state['allowance_resets_at'] <= $time) {
            $state = ['send_count' => 0, 'resend_available_at' => null, 'allowance_resets_at' => null];
        }

        unset($state['last_sent_at']);

        return [...$state, 'server_time' => $time];
    }

    private function normalize(string $phone): string
    {
        return PhoneNormalizer::normalize($phone) ?? throw ValidationException::withMessages(['phone' => 'Введите корректный номер телефона.']);
    }

    private function deliver(string $purpose, string $phone, UserRole $accountType, int $sendCount): bool
    {
        $dummyCode = config('services.oson_sms.dummy_code');
        $code = config('services.oson_sms.driver') === 'dummy' && filled($dummyCode)
            ? (string) $dummyCode
            : (string) random_int(100000, 999999);
        $otp = OneTimePassword::create(['purpose' => $purpose, 'account_type' => $accountType, 'phone' => $phone, 'code_hash' => Hash::make($code), 'transaction_id' => (string) Str::uuid(), 'status' => 'pending', 'expires_at' => now()->addMinutes(5), 'attempts' => 0]);

        try {
            $accountLabel = $accountType === UserRole::Pharmacy ? 'аптеки' : 'поставщика';
            $provider = app()->environment(['local', 'testing']) && filled(config('auth.development_otp_code'))
                ? ['transaction_id' => $otp->transaction_id, 'message_id' => null]
                : $this->smsGateway->send($phone, "Код подтверждения OAPTEKA для {$accountLabel}: {$code}. Не сообщайте его никому.", $otp->transaction_id);
            $sentAt = now();
            DB::transaction(function () use ($otp, $purpose, $phone, $accountType, $provider, $sentAt): void {
                OneTimePassword::where('purpose', $purpose)->where('account_type', $accountType)->where('phone', $phone)->whereKeyNot($otp->id)->whereNull('consumed_at')->update(['status' => 'invalidated', 'consumed_at' => $sentAt]);
                $otp->update(['status' => 'sent', 'sent_at' => $sentAt, 'expires_at' => $sentAt->copy()->addMinutes(5), 'provider_transaction_id' => $provider['transaction_id'], 'provider_message_id' => $provider['message_id']]);
            });
            Cache::put('otp:allowance:'.hash('sha256', $phone), [
                'send_count' => $sendCount + 1,
                'last_sent_at' => $sentAt->getTimestampMs(),
                'resend_available_at' => $sentAt->copy()->addSeconds(60)->getTimestampMs(),
                'allowance_resets_at' => $sentAt->copy()->addMinutes(30)->getTimestampMs(),
            ], $sentAt->copy()->addMinutes(30));

            return true;
        } catch (\Throwable) {
            $otp->update(['status' => 'failed']);

            return false;
        }
    }

    public function consume(string $purpose, string $phone, UserRole $accountType, string $code): bool
    {
        $phone = $this->normalize($phone);
        $otp = OneTimePassword::where('purpose', $purpose)->where('account_type', $accountType)->where('phone', $phone)->where('status', 'sent')->whereNull('consumed_at')->latest('id')->first();
        $dummyCode = config('services.oson_sms.dummy_code');
        if (config('services.oson_sms.driver') === 'dummy' && filled($dummyCode)) {
            if (! hash_equals((string) $dummyCode, $code)) {
                return false;
            }

            $otp?->update(['status' => 'consumed', 'consumed_at' => now()]);

            return true;
        }

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
