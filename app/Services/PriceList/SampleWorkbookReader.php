<?php

namespace App\Services\PriceList;

use App\Enums\SupplierFileType;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;

class SampleWorkbookReader
{
    /** @return array{worksheets:list<array{name:string,highest_row:int,highest_column:int}>} */
    public function inspect(string $path, string $extension): array
    {
        $information = $this->reader($extension)->listWorksheetInfo($this->absolutePath($path));
        if ($information === []) {
            throw new RuntimeException('В файле нет доступных листов.');
        }

        return ['worksheets' => array_values(array_map(fn (array $worksheet): array => [
            'name' => (string) $worksheet['worksheetName'],
            'highest_row' => (int) $worksheet['totalRows'],
            'highest_column' => (int) $worksheet['totalColumns'],
        ], $information))];
    }

    /** @return array{worksheets:list<string>,worksheet:string,highest_row:int,highest_column:int,columns:list<string>,rows:list<array{number:int,cells:array<string,string>}>,truncated:bool} */
    public function preview(string $path, string $extension, ?string $requestedWorksheet = null): array
    {
        $inspection = $this->inspect($path, $extension);
        $worksheetNames = array_column($inspection['worksheets'], 'name');
        $worksheet = $requestedWorksheet ?: $worksheetNames[0];
        $metadata = collect($inspection['worksheets'])->firstWhere('name', $worksheet);
        if ($metadata === null) {
            throw new RuntimeException('Указанный лист не найден.');
        }

        $highestRow = (int) $metadata['highest_row'];
        $highestColumn = (int) $metadata['highest_column'];
        $reader = $this->reader($extension);
        $reader->setLoadSheetsOnly($worksheet);
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= 100;
            }
        });
        $spreadsheet = $reader->load($this->absolutePath($path));
        $sheet = $spreadsheet->getSheetByName($worksheet);
        if ($sheet === null) {
            throw new RuntimeException('Указанный лист не найден.');
        }

        $columns = [];
        for ($column = 1; $column <= $highestColumn; $column++) {
            $columns[] = Coordinate::stringFromColumnIndex($column);
        }
        $rows = [];
        for ($row = 1; $row <= min(100, $highestRow); $row++) {
            $cells = [];
            foreach ($columns as $column) {
                $cell = $sheet->getCell($column.$row);
                $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getFormattedValue();
                $cells[$column] = is_scalar($value) ? (string) $value : '';
            }
            $rows[] = ['number' => $row, 'cells' => $cells];
        }
        $spreadsheet->disconnectWorksheets();

        return [
            'worksheets' => $worksheetNames,
            'worksheet' => $worksheet,
            'highest_row' => $highestRow,
            'highest_column' => $highestColumn,
            'columns' => $columns,
            'rows' => $rows,
            'truncated' => $highestRow > 100,
        ];
    }

    private function reader(string $extension): IReader
    {
        $reader = match (SupplierFileType::tryFrom(strtolower($extension))) {
            SupplierFileType::Csv => new Csv,
            SupplierFileType::Xls => new Xls,
            SupplierFileType::Xlsx => new Xlsx,
            default => throw new RuntimeException('Неподдерживаемый тип файла.'),
        };
        $reader->setReadDataOnly(true);
        if ($reader instanceof Csv) {
            $csv = ProfileValidator::defaults()['csv'];
            $reader->setDelimiter($csv['delimiter'])->setEnclosure($csv['enclosure'])->setInputEncoding($csv['encoding']);
        }

        return $reader;
    }

    private function absolutePath(string $path): string
    {
        return Storage::disk(config('price-list-imports.disk'))->path($path);
    }
}
