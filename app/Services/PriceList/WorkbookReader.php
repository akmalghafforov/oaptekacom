<?php

namespace App\Services\PriceList;

use App\Enums\SupplierFileType;
use App\Models\PriceListImport;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use RuntimeException;

class WorkbookReader
{
    /** @return array{worksheets:list<string>, highest_row:int, highest_column:int} */
    public function inspect(PriceListImport $import): array
    {
        $reader = $this->reader($import);
        $info = $reader->listWorksheetInfo($this->path($import));

        return [
            'worksheets' => array_values(array_map(fn (array $sheet): string => $sheet['worksheetName'], $info)),
            'highest_row' => max(array_column($info, 'totalRows') ?: [0]),
            'highest_column' => max(array_column($info, 'totalColumns') ?: [0]),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(PriceListImport $import, int $startRow, int $endRow): array
    {
        $profile = array_replace($import->profile_snapshot, $import->effective_layout ?? []);
        $reader = $this->reader($import);
        $reader->setReadFilter(new class($startRow, $endRow) implements IReadFilter
        {
            public function __construct(private readonly int $start, private readonly int $end) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row >= $this->start && $row <= $this->end;
            }
        });
        if (($profile['worksheet'] ?? null) !== null) {
            $reader->setLoadSheetsOnly((string) $profile['worksheet']);
        }
        $spreadsheet = $reader->load($this->path($import));
        $sheet = ($profile['worksheet'] ?? null) ? $spreadsheet->getSheetByName((string) $profile['worksheet']) : $spreadsheet->getActiveSheet();
        if ($sheet === null) {
            throw new RuntimeException('Указанный лист не найден.');
        }
        $maximumColumn = max(array_map(fn (string $column): int => Coordinate::columnIndexFromString($column), array_filter($profile['mapping'])));
        $rows = [];
        for ($row = $startRow; $row <= $endRow; $row++) {
            $values = [];
            for ($column = 1; $column <= $maximumColumn; $column++) {
                $cell = $sheet->getCell([$column, $row]);
                $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                $values[Coordinate::stringFromColumnIndex($column)] = $value;
            }
            $rows[$row] = $values;
        }
        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    public function highestDataRow(PriceListImport $import, int $reportedHighestRow): int
    {
        $profile = array_replace($import->profile_snapshot, $import->effective_layout ?? []);
        $start = (int) $profile['data_row'];
        $limit = (int) $profile['empty_row_limit'];
        $lastDataRow = $start - 1;
        $consecutiveEmpty = 0;
        $chunkSize = (int) config('price-list-imports.chunk_size');
        for ($chunkStart = $start; $chunkStart <= $reportedHighestRow; $chunkStart += $chunkSize) {
            foreach ($this->rows($import, $chunkStart, min($chunkStart + $chunkSize - 1, $reportedHighestRow)) as $rowNumber => $row) {
                $hasValue = collect($profile['mapping'])->filter()->contains(fn (string $column): bool => trim((string) ($row[$column] ?? '')) !== '');
                if ($hasValue) {
                    $lastDataRow = $rowNumber;
                    $consecutiveEmpty = 0;
                } else {
                    $consecutiveEmpty++;
                }
                if ($consecutiveEmpty >= $limit) {
                    return $lastDataRow;
                }
            }
        }

        return $lastDataRow;
    }

    private function reader(PriceListImport $import): IReader
    {
        $type = SupplierFileType::from(strtolower(pathinfo((string) $import->original_filename, PATHINFO_EXTENSION)));
        $reader = match ($type) {
            SupplierFileType::Csv, SupplierFileType::Tsv => new Csv, SupplierFileType::Xls => new Xls, SupplierFileType::Xlsx => new Xlsx
        };
        $reader->setReadDataOnly(true);
        if ($reader instanceof Csv) {
            $csv = $import->profile_snapshot['csv'];
            if ($type === SupplierFileType::Tsv) {
                $csv['delimiter'] = "\t";
            }
            $reader->setDelimiter($csv['delimiter'])->setEnclosure($csv['enclosure'])->setInputEncoding($csv['encoding']);
        }

        return $reader;
    }

    private function path(PriceListImport $import): string
    {
        return Storage::disk(config('price-list-imports.disk'))->path($import->file_path);
    }
}
