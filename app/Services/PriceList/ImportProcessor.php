<?php

namespace App\Services\PriceList;

use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Models\Medicine;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\SupplierProduct;
use App\Models\SupplierProductAlias;

class ImportProcessor
{
    public function __construct(private readonly RowParser $parser) {}

    /** @param array<int, array<string, mixed>> $rows */
    public function process(PriceListImport $import, array $rows): void
    {
        $profile = array_replace($import->profile_snapshot, $import->effective_layout ?? [], ['_inventory_at' => $import->inventory_at?->toDateString(), '_category_rule_set_id' => $import->product_category_rule_set_id, '_source_filename' => $import->original_filename]);
        foreach ($rows as $sourceRow => $rawRow) {
            $result = $this->parser->parse($rawRow, $sourceRow, $profile);
            $result['source_worksheet'] = $profile['worksheet'] ?? null;
            if (in_array($result['disposition'], [PriceListRowDisposition::Valid, PriceListRowDisposition::Warning], true)) {
                $result = $this->match($import, $result);
                if (! $import->profile_snapshot['keep_exact_duplicates'] && PriceListImportRow::query()->whereBelongsTo($import, 'import')->where('offer_fingerprint', $result['offer_fingerprint'])->where('source_row', '!=', $sourceRow)->exists()) {
                    $result['disposition'] = PriceListRowDisposition::Skipped;
                    $result['planned_action'] = PriceListRowAction::Skip;
                    $result['warnings'][] = 'Полный дубликат предложения.';
                }
            }
            PriceListImportRow::updateOrCreate(
                ['price_list_import_id' => $import->id, 'source_row' => $sourceRow],
                $result,
            );
        }
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function match(PriceListImport $import, array $result): array
    {
        $values = $result['parsed_values'];
        $supplierProduct = null;
        if ($values['normalized_sku'] !== null && $import->profile_snapshot['matching_strategy'] !== 'name') {
            $supplierProduct = SupplierProduct::query()->where('supplier_organization_id', $import->supplier_organization_id)->where('normalized_sku', $values['normalized_sku'])->first();
        }
        $supplierProduct ??= SupplierProductAlias::query()->with('supplierProduct')->where('supplier_organization_id', $import->supplier_organization_id)->where('normalized_name', $values['normalized_name'])->first()?->supplierProduct;
        $supplierProduct ??= SupplierProduct::query()->where('supplier_organization_id', $import->supplier_organization_id)->where('normalized_name', $values['normalized_name'])->first();
        if ($supplierProduct !== null) {
            $result['supplier_product_id'] = $supplierProduct->id;
            $result['medicine_id'] = $supplierProduct->medicine_id;
            $result['planned_action'] = PriceListRowAction::Update;

            return $result;
        }

        $candidates = Medicine::query()->where('normalized_name', $values['normalized_name'])->get()->filter(function (Medicine $medicine) use ($values): bool {
            foreach (['manufacturer' => 'manufacturer', 'country' => 'country_of_origin', 'unit' => 'unit_of_measure'] as $source => $canonical) {
                if (($values[$source] ?? null) && $medicine->{$canonical} && app(ValueNormalizer::class)->name($values[$source]) !== app(ValueNormalizer::class)->name($medicine->{$canonical})) {
                    return false;
                }
            }

            return true;
        });
        if ($candidates->count() > 1) {
            $result['disposition'] = PriceListRowDisposition::Error;
            $result['errors'][] = 'Найдено несколько совместимых товаров. Выберите сопоставление вручную.';

            return $result;
        }
        if ($candidates->count() === 1) {
            $result['medicine_id'] = $candidates->first()->id;
            $result['planned_action'] = PriceListRowAction::Match;
        }

        return $result;
    }
}
