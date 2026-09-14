<?php

namespace App\Services\PriceList;

use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\ProductCategoryRuleSet;

class ImportProcessor
{
    public function __construct(
        private readonly RowParser $parser,
        private readonly SupplierProductMatcher $matcher,
        private readonly ProductCategoryClassifier $classifier,
    ) {}

    /** @param array<int, array<string, mixed>> $rows */
    public function process(PriceListImport $import, array $rows): void
    {
        $profile = array_replace($import->profile_snapshot, $import->effective_layout ?? [], ['_inventory_at' => $import->inventory_at?->toDateString(), '_category_rule_set_id' => $import->product_category_rule_set_id, '_source_filename' => $import->original_filename]);
        foreach ($rows as $sourceRow => $rawRow) {
            $result = $this->parser->parse($rawRow, $sourceRow, $profile);
            $result['source_worksheet'] = $profile['worksheet'] ?? null;
            if (in_array($result['disposition'], [PriceListRowDisposition::Valid, PriceListRowDisposition::Warning], true)) {
                $result = $this->matcher->match($import, $result);
                if (($result['medicine_id'] ?? null) === null && $result['disposition'] !== PriceListRowDisposition::Error) {
                    $result = $this->categorizeNewProduct($import, $result);
                }
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
    private function categorizeNewProduct(PriceListImport $import, array $result): array
    {
        $values = $result['parsed_values'];
        $existing = PriceListImportRow::query()
            ->whereBelongsTo($import, 'import')
            ->where('normalized_product_name', $values['normalized_name'])
            ->whereNotNull('categorization_status')
            ->first();
        if ($existing !== null) {
            return array_replace($result, $existing->only(['assigned_category', 'matched_keyword', 'matched_source_text', 'categorization_confidence', 'categorization_status', 'categorization_evidence']));
        }

        $ruleSet = ProductCategoryRuleSet::find($import->product_category_rule_set_id);
        if ($ruleSet === null) {
            return $result;
        }
        $category = $this->classifier->classify($values['normalized_name'], $ruleSet);
        $result = array_replace($result, [
            'source_filename' => $import->original_filename,
            'original_product_name' => $values['name'],
            'normalized_product_name' => $values['normalized_name'],
            'assigned_category' => $category['category'],
            'matched_keyword' => $category['keyword'],
            'matched_source_text' => $category['sourceText'],
            'categorization_confidence' => $category['confidence'],
            'categorization_status' => $category['status'],
            'categorization_evidence' => $category['evidence'],
        ]);
        if (in_array($category['status'], ['unmatched', 'ambiguous'], true)) {
            $result['warnings'][] = $category['status'] === 'ambiguous' ? 'Категория товара неоднозначна и требует проверки.' : 'Категория товара не распознана.';
            $result['disposition'] = PriceListRowDisposition::Warning;
        }

        return $result;
    }
}
