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
            $medicine = $supplierProduct->medicine()->firstOrFail();
            $categories = $medicine->categories()
                ->select(['product_categories.code', 'product_categories.label', 'medicine_product_category.confidence'])
                ->toBase()->get();
            $result['supplier_product_id'] = $supplierProduct->id;
            $result['medicine_id'] = $medicine->id;
            $result['planned_action'] = PriceListRowAction::Update;
            $result['assigned_category'] = $medicine->category;
            $result['assigned_categories'] = $categories->pluck('code')->all();
            $result['category_candidates'] = $categories->map(fn ($category): array => ['code' => $category->code, 'label' => $category->label, 'confidence' => $category->confidence, 'decision' => 'accepted'])->all();
            $result['categorization_status'] = $medicine->categories_locked_at ? 'manual_locked' : $medicine->category_status;
            $result['categorization_confidence'] = $medicine->category_confidence;
            $result['categorization_evidence'] = $medicine->category_evidence === null ? null : app(CategoryEvidence::class)->sanitize($medicine->category_evidence);
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
