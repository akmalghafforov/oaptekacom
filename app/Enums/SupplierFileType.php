<?php

namespace App\Enums;

enum SupplierFileType: string
{
    case Csv = 'csv';
    case Tsv = 'tsv';
    case Xls = 'xls';
    case Xlsx = 'xlsx';
}
