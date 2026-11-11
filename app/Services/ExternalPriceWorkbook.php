<?php

namespace App\Services;

use App\DataTransferObjects\StoredImportFile;
use App\Enums\SupplierFileType;
use App\Services\PriceList\ProfileValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class ExternalPriceWorkbook
{
    private const FIELDS = ['name', 'price', 'external_id', 'manufacturer', 'quantity', 'expiry', 'original_price', 'discount_percent'];

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public static function profile(array $options = []): array
    {
        return app(ProfileValidator::class)->validate(array_replace($options, [
            'worksheet' => 'Prices', 'header_row' => 1, 'data_row' => 2,
            'mapping' => ['name' => 'A', 'price' => 'B', 'sku' => 'C', 'manufacturer' => 'D', 'quantity' => 'E', 'expiration' => 'F'],
        ]));
    }

    /** @param list<array<string, mixed>> $items */
    public function fingerprint(array $items): string
    {
        $rows = array_map(function (array $item): string {
            $row = [];
            foreach (self::FIELDS as $field) {
                $row[$field] = isset($item[$field]) ? (string) $item[$field] : '';
            }

            return json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }, $items);
        sort($rows, SORT_STRING);

        return hash('sha256', implode("\n", $rows));
    }

    /** @param list<array<string, mixed>> $items */
    public function store(array $items): StoredImportFile
    {
        if ($items === [] || count($items) + 1 > config('price-list-imports.max_rows')) {
            throw new RuntimeException('Empty or oversized external catalog.');
        }
        usort($items, fn (array $a, array $b): int => strcmp((string) $a['external_id'], (string) $b['external_id']));
        $book = new Spreadsheet;
        $book->getProperties()->setCreated(0)->setModified(0);
        $sheet = $book->getActiveSheet()->setTitle('Prices');
        foreach ([self::FIELDS, ...array_map(fn (array $item): array => array_map(fn (string $field): string => (string) ($item[$field] ?? ''), self::FIELDS), $items)] as $row => $values) {
            foreach ($values as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $row + 1], $value, DataType::TYPE_STRING);
            }
        }
        $disk = Storage::disk(config('price-list-imports.disk'));
        $path = 'price-list-imports/external/'.Str::uuid().'.xlsx';
        $disk->makeDirectory('price-list-imports/external');
        try {
            (new Xlsx($book))->save($disk->path($path));
            $archive = new \ZipArchive;
            if ($archive->open($disk->path($path)) !== true) {
                throw new RuntimeException('Could not finalize external workbook.');
            }
            try {
                for ($index = 0; $index < $archive->numFiles; $index++) {
                    $archive->setMtimeName($archive->getNameIndex($index), 315532800);
                }
            } finally {
                $archive->close();
            }
            app(ImportFileValidator::class)->validate($disk->path($path), 'prices.xlsx', SupplierFileType::Xlsx);

            return new StoredImportFile(config('price-list-imports.disk'), $path, 'prices.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $disk->size($path), hash_file('sha256', $disk->path($path)));
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /** @return array{date: ?CarbonImmutable, precision: ?string} */
    public static function publicationDate(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return ['date' => null, 'precision' => null];
        }
        foreach (['!d.m.Y' => 'day', '!Y-m-d' => 'day', '!d.m.Y H:i' => 'minute', '!d.m.Y H:i:s' => 'second', '!Y-m-d\TH:i:sP' => 'second', '!Y-m-d\TH:i:s.uP' => 'second', '!Y-m-d H:i:s' => 'second', '!Y-m-d\TH:i:s' => 'second', '!Y-m-d\TH:i' => 'minute'] as $format => $precision) {
            try {
                $date = CarbonImmutable::createFromFormat($format, trim($value), config('price-list-imports.timezone'));
                $errors = CarbonImmutable::getLastErrors();
                if ($date && (! $errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return ['date' => $date->setTimezone(config('price-list-imports.timezone')), 'precision' => $precision];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return ['date' => null, 'precision' => null];
    }
}
