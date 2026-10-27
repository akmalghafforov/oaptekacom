<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Session\Session;

class LoginSessionLifetime
{
    public const string EXPIRES_AT = 'login_expires_at';

    public function initialize(Session $session): void
    {
        $session->put(self::EXPIRES_AT, now()->addDays(30)->timestamp);
    }

    public function isExpired(Session $session): bool
    {
        return $session->has(self::EXPIRES_AT)
            && $session->get(self::EXPIRES_AT) <= now()->timestamp;
    }

    public function extend(Session $session): void
    {
        if (! $session->has(self::EXPIRES_AT)) {
            $this->initialize($session);
        }

        $session->put(self::EXPIRES_AT, max($session->get(self::EXPIRES_AT), now()->addDays(10)->timestamp));
    }

    public function storedSessionIsActive(string $sessionId): bool
    {
        $handler = clone app('session')->driver()->getHandler();
        $payload = $handler->read($sessionId);
        if (config('session.encrypt')) {
            try {
                $payload = app('encrypter')->decrypt($payload);
            } catch (DecryptException) {
                return false;
            }
        }
        $attributes = config('session.serialization') === 'json'
            ? json_decode($payload, true)
            : @unserialize($payload, ['allowed_classes' => false]);

        return is_array($attributes)
            && isset($attributes[auth()->guard()->getName()])
            && (! isset($attributes[self::EXPIRES_AT]) || $attributes[self::EXPIRES_AT] > now()->timestamp);
    }
}
