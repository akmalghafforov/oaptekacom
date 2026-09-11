<?php

namespace App\Enums;

enum SubscriptionTerm: string
{
    case TenDays = 'ten_days';
    case Month = 'month';
    case Year = 'year';
    case SixMonths = 'six_months';
    case ThreeMonths = 'three_months';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::TenDays => '10 дней', self::Month => '1 месяц', self::Year => '1 год', self::SixMonths => '6 месяцев', self::ThreeMonths => '3 месяца', self::Custom => 'Произвольный срок',
        };
    }
}
