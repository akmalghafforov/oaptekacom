<?php

namespace App\Services;

use App\Enums\SupplierFileType;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;
use ZipArchive;

class ImportFileValidator
{
    public function validate(string $path, string $filename, SupplierFileType $type, array $configuration = []): void
    {
        try {
            if (mb_strlen($filename) > 255 || str_contains($filename, "\0") || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== $type->value || ! filesize($path) || filesize($path) > config('price-list-imports.max_file_kilobytes') * 1024) {
                throw new \RuntimeException;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $allowed = match ($type) {
                SupplierFileType::Csv, SupplierFileType::Tsv => ['text/plain', 'text/csv', 'text/tab-separated-values', 'application/csv'],
                SupplierFileType::Xls => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'],
                SupplierFileType::Xlsx => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            };
            if (! in_array($mime, $allowed, true)) {
                throw new \RuntimeException;
            }
            if ($type === SupplierFileType::Xlsx) {
                $zip = new ZipArchive;
                if ($zip->open($path) !== true) {
                    throw new \RuntimeException;
                }
                try {
                    $expanded = 0;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entry = $zip->statIndex($i);
                        $expanded += $entry['size'];
                        if ($expanded > config('price-list-imports.max_expanded_file_bytes') || ($entry['encryption_method'] ?? 0) !== 0) {
                            throw new \RuntimeException;
                        }
                    }
                } finally {
                    $zip->close();
                }
            }
            $reader = match ($type) {
                SupplierFileType::Xls => new Xls, SupplierFileType::Xlsx => new Xlsx, default => new Csv
            };
            if ($reader instanceof Csv) {
                $csv = $configuration['csv'] ?? [];
                $reader->setDelimiter($type === SupplierFileType::Tsv ? "\t" : ($csv['delimiter'] ?? null));
                $reader->setEnclosure($csv['enclosure'] ?? '"')->setInputEncoding($csv['encoding'] ?? 'UTF-8');
            }
            if (! $reader->canRead($path)) {
                throw new \RuntimeException;
            }
            $worksheets = $reader->listWorksheetInfo($path);
            if (! $worksheets || max(array_column($worksheets, 'totalRows')) === 0 || max(array_column($worksheets, 'totalColumns')) === 0) {
                throw new \RuntimeException;
            }
            foreach ($worksheets as $sheet) {
                if ($sheet['totalRows'] > config('price-list-imports.max_rows') || $sheet['totalColumns'] > config('price-list-imports.max_columns')) {
                    throw new \RuntimeException;
                }
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'invalid_attachment']);
        }
    }
}
