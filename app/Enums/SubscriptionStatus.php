<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Superseded = 'superseded';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает оплаты', self::Active => 'Активна', self::Superseded => 'Заменена', self::Expired => 'Истекла', self::Cancelled => 'Отменена',
        };
    }
}
