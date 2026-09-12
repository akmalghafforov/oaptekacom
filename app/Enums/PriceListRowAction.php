<?php

namespace App\Enums;

enum PriceListRowAction: string
{
    case Create = 'create';
    case Match = 'match';
    case Update = 'update';
    case Skip = 'skip';
}
