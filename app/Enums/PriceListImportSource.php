<?php

namespace App\Enums;

enum PriceListImportSource: string
{
    case Manual = 'manual';
    case Email = 'email';
    case Icorp = 'icorp';
    case PriceListTj = 'pricelist_tj';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Ручная загрузка', self::Email => 'Электронная почта',
            self::Icorp => 'iCorp', self::PriceListTj => 'PriceList-TJ',
        };
    }
}
