<?php

namespace App\Services\PriceList;

use App\Models\PriceListImport;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ImportLayoutDetector
{
    /** @param array{worksheets:list<string>,highest_row:int,highest_column:int} $inspection @return array{layout:array<string,mixed>,warning:?string,confidence:string} */
    public function detect(PriceListImport $import, array $inspection): array
    {
        $profile = $import->profile_snapshot;
        $worksheet = $profile['worksheet'] ?? null;
        $nameColumn = $profile['mapping']['name'] ?? null;
        $worksheetAvailable = $worksheet === null || in_array($worksheet, $inspection['worksheets'], true);
        $columnAvailable = $nameColumn !== null && Coordinate::columnIndexFromString($nameColumn) <= $inspection['highest_column'];
        if ($worksheetAvailable && $columnAvailable) {
            return ['layout' => ['worksheet' => $worksheet, 'mapping' => $profile['mapping'], 'data_row' => $profile['data_row']], 'warning' => null, 'confidence' => 'profile'];
        }
        $fallbackWorksheet = $inspection['worksheets'][0] ?? $worksheet;

        return ['layout' => ['worksheet' => $fallbackWorksheet, 'mapping' => $profile['mapping'], 'data_row' => $profile['data_row']], 'warning' => 'Профильная раскладка не полностью доступна в файле; требуется проверка раскладки.', 'confidence' => 'low'];
    }
}
