<?php

namespace App\Enums;

enum MatchingStrategy: string
{
    case Name = 'name';
    case Sku = 'sku';
    case SkuThenName = 'sku_then_name';
}
