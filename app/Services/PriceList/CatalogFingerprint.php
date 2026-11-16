<?php

namespace App\Services\PriceList;

use App\Enums\PriceListRowDisposition;
use App\Models\PriceListImport;
use App\Models\SupplierProduct;
use App\Models\SupplierProductAlias;

class CatalogFingerprint
{
    public const VERSION = 1;

    public function calculate(PriceListImport $import): ?string
    {
        $hashes = $this->rowHashes($import);

        return $hashes === [] ? null : 'catalog-v'.self::VERSION.':'.hash('sha256', implode('', $hashes));
    }

    /** @return array{only_candidate: int, only_other: int} */
    public function difference(PriceListImport $candidate, PriceListImport $other): array
    {
        $candidateRows = array_count_values($this->rowHashes($candidate));
        $otherRows = array_count_values($this->rowHashes($other));
        $onlyCandidate = 0;
        $onlyOther = 0;
        foreach ($candidateRows as $hash => $count) {
            $onlyCandidate += max(0, $count - ($otherRows[$hash] ?? 0));
        }
        foreach ($otherRows as $hash => $count) {
            $onlyOther += max(0, $count - ($candidateRows[$hash] ?? 0));
        }

        return ['only_candidate' => $onlyCandidate, 'only_other' => $onlyOther];
    }

    /** @return list<string> */
    private function rowHashes(PriceListImport $import): array
    {
        $identities = SupplierProduct::where('supplier_organization_id', $import->supplier_organization_id)->pluck('normalized_name', 'id');
        $aliases = SupplierProductAlias::where('supplier_organization_id', $import->supplier_organization_id)->pluck('supplier_product_id', 'normalized_name');
        $hashes = [];
        foreach ($import->rows()->whereIn('disposition', [PriceListRowDisposition::Valid, PriceListRowDisposition::Warning])->lazyById(500) as $row) {
            $values = $row->parsed_values;
            $name = $values['normalized_name'] ?? '';
            $productId = $row->supplier_product_id ?? $aliases->get($name);
            $canonical = ['product' => $identities->get($productId) ?? $name];
            foreach (['name', 'normalized_name', 'normalized_sku', 'manufacturer', 'country', 'unit', 'inn', 'form', 'dosage', 'batch', 'expiration', 'price', 'quantity', 'total'] as $field) {
                $value = $values[$field] ?? null;
                if ($value !== null && in_array($field, ['price', 'quantity', 'total'], true)) {
                    $value = rtrim(rtrim((string) $value, '0'), '.');
                    if (! str_contains((string) $values[$field], '.')) {
                        $value = (string) $values[$field];
                    }
                }
                $canonical[$field] = $value === '' ? null : $value;
            }
            $hashes[] = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        }
        sort($hashes, SORT_STRING);

        return $hashes;
    }
}
