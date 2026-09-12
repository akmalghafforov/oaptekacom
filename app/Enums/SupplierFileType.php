<?php

namespace App\Enums;

enum SupplierFileType: string
{
    case Csv = 'csv';
    case Xls = 'xls';
    case Xlsx = 'xlsx';
}
