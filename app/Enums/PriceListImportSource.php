<?php

namespace App\Enums;

enum PriceListImportSource: string
{
    case Manual = 'manual';
    case Email = 'email';

    public function label(): string
    {
        return $this === self::Manual ? 'Ручная загрузка' : 'Электронная почта';
    }
}
