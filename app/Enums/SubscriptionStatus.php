<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активна', self::Superseded => 'Заменена', self::Expired => 'Истекла',
        };
    }
}
