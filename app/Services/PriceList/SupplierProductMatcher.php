<?php

namespace App\Services\PriceList;

use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Models\PriceListImport;
use App\Models\SupplierProduct;
use App\Models\SupplierProductAlias;

class SupplierProductMatcher
{
    /** @param array<string, mixed> $result @return array<string, mixed> */
    public function match(PriceListImport $import, array $result): array
    {
        $values = $result['parsed_values'];
        $supplierProduct = SupplierProduct::query()
            ->where('supplier_organization_id', $import->supplier_organization_id)
            ->where('normalized_name', $values['normalized_name'])
            ->first();
        $aliasProduct = SupplierProductAlias::query()
            ->with('supplierProduct')
            ->where('supplier_organization_id', $import->supplier_organization_id)
            ->where('normalized_name', $values['normalized_name'])
            ->first()?->supplierProduct;

        if ($supplierProduct !== null && $aliasProduct !== null && $supplierProduct->id !== $aliasProduct->id) {
            return $this->error($result, 'Название совпадает одновременно с каноническим товаром и псевдонимом другого товара.');
        }

        $supplierProduct ??= $aliasProduct;

        if ($supplierProduct !== null) {
            $medicine = $supplierProduct->medicine()->with('categories')->firstOrFail();
            $result['supplier_product_id'] = $supplierProduct->id;
            $result['medicine_id'] = $medicine->id;
            $result['planned_action'] = PriceListRowAction::Update;
            $result['assigned_category'] = $medicine->category;
            $result['assigned_categories'] = $medicine->categories->pluck('code')->all();
            $result['category_candidates'] = $medicine->categories->map(fn ($category): array => ['code' => $category->code, 'label' => $category->label, 'confidence' => $category->pivot->confidence, 'decision' => 'accepted', 'evidence' => $category->pivot->evidence])->all();
            $result['categorization_status'] = $medicine->categories_locked_at ? 'manual_locked' : $medicine->category_status;
            $result['categorization_confidence'] = $medicine->category_confidence;
            $result['categorization_evidence'] = $medicine->category_evidence;
        }

        return $result;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function error(array $result, string $message): array
    {
        $result['disposition'] = PriceListRowDisposition::Error;
        $result['errors'][] = $message;

        return $result;
    }
}
