<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Superseded = 'superseded';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает проверки', self::Active => 'Активна', self::Superseded => 'Заменена', self::Expired => 'Истекла', self::Cancelled => 'Отменена', self::Rejected => 'Отклонена',
        };
    }
}
