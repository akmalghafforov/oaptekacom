<?php

namespace App\Enums;

enum ExpirationMode: string
{
    case Date = 'date';
    case ShelfLife = 'shelf_life';
}
