<?php

namespace App\Enums;

enum PriceListRowDisposition: string
{
    case Valid = 'valid';
    case Warning = 'warning';
    case Error = 'error';
    case Skipped = 'skipped';
}
