<?php

namespace App\Services\PriceList;

use App\Models\PriceListImport;
use App\Models\ProductCategoryCandidate;

class CategoryCandidateExtractor
{
    public function __construct(private readonly ProductNameNormalizer $normalizer) {}

    public function extract(PriceListImport $import): void
    {
        $groups = [];
        $import->rows()->whereIn('categorization_status', ['uncategorized', 'attention_needed'])->each(function ($row) use (&$groups): void {
            $tokens = array_values(array_filter($this->normalizer->normalize($row->original_product_name)['tokens'], fn (string $token): bool => ! preg_match('/\d|^(мг|мл|г|кг|шт|уп|фл|ваг|рект|шприц)$/u', $token)));
            foreach (array_slice($tokens, 0, 3) as $length) {
                $phrase = implode(' ', array_slice($tokens, 0, array_search($length, $tokens, true) + 1));
                if (mb_strlen($phrase) < 4) {
                    continue;
                }
                $groups[$phrase]['occurrences'] = ($groups[$phrase]['occurrences'] ?? 0) + 1;
                $groups[$phrase]['examples'][] = $row->original_product_name;
            }
        });
        foreach ($groups as $phrase => $group) {
            ProductCategoryCandidate::updateOrCreate(['price_list_import_id' => $import->id, 'normalized_phrase' => $phrase], ['occurrences' => $group['occurrences'], 'examples' => array_slice(array_values(array_unique($group['examples'])), 0, 5)]);
        }
    }
}
