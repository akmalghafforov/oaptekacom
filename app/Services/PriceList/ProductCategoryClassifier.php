<?php

namespace App\Services\PriceList;

use App\Enums\ProductCategory;
use App\Models\ProductCategoryRuleSet;

class ProductCategoryClassifier
{
    public function __construct(private readonly ProductNameNormalizer $normalizer) {}

    /** @return array<string, mixed> */
    public function classify(string $name, ProductCategoryRuleSet $ruleSet): array
    {
        $normalized = $this->normalizer->normalize($name);
        $matches = [];
        foreach ($ruleSet->rules()->where('is_enabled', true)->orderBy('priority')->get() as $rule) {
            if ($this->matches($normalized['tokens'], $normalized['attached_forms'], $rule->normalized_matcher, $rule->context_requirements ?? [], $rule->context_exclusions ?? [])) {
                $matches[] = ['category' => $rule->category, 'keyword' => $rule->literal_keyword, 'source_text' => $rule->normalized_matcher, 'confidence' => $rule->confidence, 'priority' => $rule->priority, 'supersedes' => $rule->supersedes_categories ?? []];
            }
        }
        foreach ($matches as $match) {
            $matches = array_values(array_filter($matches, fn (array $candidate): bool => $candidate === $match || ! in_array($candidate['category'], $match['supersedes'], true)));
        }
        $categories = array_values(array_unique(array_column($matches, 'category')));
        if ($matches === []) {
            return $this->result($normalized['normalized'], ProductCategory::Unrecognized->value, 'unmatched', 0, null, null, []);
        }
        if (count($categories) > 1) {
            return $this->result($normalized['normalized'], ProductCategory::Unrecognized->value, 'ambiguous', 30, null, null, $matches);
        }
        usort($matches, fn (array $left, array $right): int => [$left['priority'], -mb_strlen($left['keyword'])] <=> [$right['priority'], -mb_strlen($right['keyword'])]);
        $match = $matches[0];

        return $this->result($normalized['normalized'], $match['category'], 'matched', $match['confidence'], $match['keyword'], $match['source_text'], $matches);
    }

    /** @param list<string> $tokens @param list<string> $attached @param list<string> $requirements @param list<string> $exclusions */
    private function matches(array $tokens, array $attached, string $phrase, array $requirements, array $exclusions): bool
    {
        $phraseTokens = preg_split('/\s+/u', $phrase) ?: [];
        $stream = implode(' ', $tokens);
        if (! str_contains(' '.$stream.' ', ' '.implode(' ', $phraseTokens).' ') && ! in_array($phrase, $attached, true)) {
            return false;
        }
        foreach ($requirements as $requirement) {
            if ($requirement === '__pharma_indicator__') {
                if (! $this->hasPharmaceuticalIndicator($tokens, $phraseTokens)) {
                    return false;
                }

                continue;
            }
            if (! in_array($requirement, $tokens, true)) {
                return false;
            }
        }
        foreach ($exclusions as $exclusion) {
            if (in_array($exclusion, $tokens, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $tokens @param list<string> $phraseTokens */
    private function hasPharmaceuticalIndicator(array $tokens, array $phraseTokens): bool
    {
        $length = count($phraseTokens);
        for ($offset = 0; $offset <= count($tokens) - $length; $offset++) {
            if (array_slice($tokens, $offset, $length) !== $phraseTokens) {
                continue;
            }
            foreach (array_slice($tokens, $offset + $length) as $token) {
                if (preg_match('/^\d/u', $token) === 1 || in_array($token, ['мг', 'мл', 'г', 'кг', 'шт', 'уп', 'упак', 'пак', 'саше', 'доза', 'табл', 'капс'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $evidence @return array<string, mixed> */
    private function result(string $normalized, string $category, string $status, int $confidence, ?string $keyword, ?string $sourceText, array $evidence): array
    {
        return compact('normalized', 'category', 'status', 'confidence', 'keyword', 'sourceText', 'evidence');
    }
}
