<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class IcorpCredentials
{
    private const PATH = 'external-prices/credentials/icorp.encrypted';

    /** @return array{phone: string, password: string, revision: string} */
    public function current(): array
    {
        if (Storage::disk('local')->exists(self::PATH)) {
            try {
                $data = json_decode(Crypt::decryptString(Storage::disk('local')->get(self::PATH)), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($data) || ! is_string($data['phone'] ?? null) || ! is_string($data['password'] ?? null) || ! is_string($data['revision'] ?? null)) {
                    throw new \RuntimeException;
                }

                return ['phone' => $data['phone'], 'password' => $data['password'], 'revision' => $data['revision']];
            } catch (Throwable) {
                throw new IcorpFailure('credentials', 'credentials_unreadable', reason: 'authentication');
            }
        }

        return ['phone' => $this->normalize((string) config('external-prices.icorp.phone')), 'password' => (string) config('external-prices.icorp.password'), 'revision' => hash_hmac('sha256', (string) config('external-prices.icorp.password'), (string) config('app.key'))];
    }

    public function normalize(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        return strlen($phone) === 12 && str_starts_with($phone, '992') ? substr($phone, 3) : $phone;
    }

    /** Caller must hold the external-prices:icorp lock. */
    public function save(string $phone, ?string $password): void
    {
        $phone = $this->normalize($phone);
        if ($password === null || $password === '') {
            $current = $this->current();
            if (! Storage::disk('local')->exists(self::PATH) || $phone !== $current['phone'] || $current['password'] === '') {
                throw ValidationException::withMessages(['password' => 'Введите пароль для этого аккаунта iCorp.']);
            }
            $password = $current['password'];
        }
        $data = ['phone' => $phone, 'password' => $password, 'revision' => bin2hex(random_bytes(16))];
        $disk = Storage::disk('local');
        $temporary = self::PATH.'.tmp';
        if (! $disk->put($temporary, Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR))) || ! $disk->move($temporary, self::PATH)) {
            throw new IcorpFailure('credentials', 'credentials_save_failed');
        }
    }

    public function sessionPath(): string
    {
        $credentials = $this->current();

        return 'external-prices/sessions/icorp-'.hash('sha256', $credentials['phone'].'|'.config('external-prices.icorp.firm_id').'|'.$credentials['revision']).'.encrypted';
    }
}
