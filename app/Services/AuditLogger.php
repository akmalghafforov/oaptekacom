<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    public function log(string $event, Model $subject, array $before = [], array $after = []): void
    {
        AuditEvent::create([
            'user_id' => auth()->id(),
            'event' => $event,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'before' => $this->withoutSecrets($before),
            'after' => $this->withoutSecrets($after),
            'ip' => request()?->ip(),
        ]);
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function withoutSecrets(array $attributes): array
    {
        unset($attributes['password'], $attributes['totp_secret'], $attributes['two_factor_secret'], $attributes['recovery_codes']);

        return $attributes;
    }
}
