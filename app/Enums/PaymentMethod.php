<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Dc = 'dc';
    case EskhataOnline = 'eskhata_online';
    case Alif = 'alif';

    public function label(): string
    {
        return match ($this) {
            self::Dc => 'DC', self::EskhataOnline => 'EskhataOnline', self::Alif => 'Alif',
        };
    }
}
