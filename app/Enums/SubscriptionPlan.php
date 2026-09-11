<?php

namespace App\Enums;

enum SubscriptionPlan: string
{
    case Free = 'free';
    case Base = 'base';
    case Premium = 'premium';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Бесплатный', self::Base => 'Базовый', self::Premium => 'Премиум',
        };
    }

    public function isPaid(): bool
    {
        return $this !== self::Free;
    }
}
