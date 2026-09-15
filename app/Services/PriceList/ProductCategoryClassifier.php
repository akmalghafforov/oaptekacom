<?php

namespace App\Services\PriceList;

use App\Enums\ProductCategory;
use App\Models\ProductCategoryRuleSet;

class ProductCategoryClassifier
{
    public function __construct(private readonly ProductNameNormalizer $normalizer) {}

    /** @param array{form?: ?string, dosage?: ?string, unit?: ?string} $context @return array<string, mixed> */
    public function classify(string $name, ProductCategoryRuleSet $ruleSet, array $context = []): array
    {
        $normalized = $this->normalizer->normalize($name);
        $matches = [];
        $invalidCategory = false;
        foreach ($ruleSet->rules()->with('productCategory')->where('is_enabled', true)->orderBy('priority')->get() as $rule) {
            $span = $this->matchingSpan($normalized['tokens'], $normalized['attached_forms'], $rule->normalized_matcher);
            if ($span === null || ! $this->contextAllows($normalized['tokens'], $rule->context_requirements ?? [], $rule->context_exclusions ?? [], $span)) {
                continue;
            }
            $catalog = $rule->productCategory;
            if ($catalog === null || ! $catalog->is_active) {
                $invalidCategory = true;

                continue;
            }
            $confidence = (int) $rule->confidence;
            if ($confidence < 85 && $this->hasCorroboration($normalized['tokens'], $span, $context, $catalog->label)) {
                $confidence = min(100, $confidence + 10);
            }
            $matches[] = ['code' => $catalog->code, 'label' => $catalog->label, 'keyword' => $rule->literal_keyword, 'source_text' => $rule->normalized_matcher, 'confidence' => $confidence, 'priority' => $rule->priority, 'span' => $span, 'supersedes' => $rule->supersedes_categories ?? [], 'decision' => $confidence >= 85 ? 'accepted' : ($confidence >= 60 ? 'review' : 'ignored')];
        }
        $matches = collect($matches)->groupBy('code')->map(fn ($group): array => $group->sortByDesc(fn (array $match): array => [$match['confidence'], mb_strlen($match['source_text']), -$match['priority']])->first())->values()->all();
        [$matches, $supersedenceConflict] = $this->applySupersedence($matches);
        $accepted = array_values(array_filter($matches, fn (array $match): bool => $match['decision'] === 'accepted'));
        $review = array_values(array_filter($matches, fn (array $match): bool => $match['decision'] === 'review'));
        $overlap = $this->hasConflictingSpan($accepted);
        $requiresReview = $invalidCategory || $supersedenceConflict || $overlap || count($accepted) > 3 || ($accepted === [] && $review !== []);
        usort($accepted, fn (array $left, array $right): int => $this->categoryOrder($left['label']) <=> $this->categoryOrder($right['label']));
        $status = $requiresReview ? 'review_required' : ($accepted === [] ? 'unmatched' : (count($accepted) > 1 ? 'multi_matched' : 'matched'));

        return ['normalized' => $normalized['normalized'], 'status' => $status, 'assignments' => $accepted, 'candidates' => $matches, 'rule_set_id' => $ruleSet->id, 'rule_set_checksum' => $ruleSet->checksum, 'category' => $accepted[0]['label'] ?? ProductCategory::Unrecognized->value, 'confidence' => $accepted[0]['confidence'] ?? ($review[0]['confidence'] ?? 0), 'keyword' => $accepted[0]['keyword'] ?? null, 'sourceText' => $accepted[0]['source_text'] ?? null, 'evidence' => ['assignments' => $accepted, 'candidates' => $matches, 'issues' => array_keys(array_filter(['unknown_or_inactive_category' => $invalidCategory, 'supersedence_conflict' => $supersedenceConflict, 'overlapping_span' => $overlap, 'too_many_assignments' => count($accepted) > 3]))]];
    }

    /** @param list<string> $tokens @param list<string> $attached @return array{0:int,1:int}|null */
    private function matchingSpan(array $tokens, array $attached, string $phrase): ?array
    {
        $parts = preg_split('/\s+/u', $phrase) ?: [];
        for ($offset = 0; $offset <= count($tokens) - count($parts); $offset++) {
            if (array_slice($tokens, $offset, count($parts)) === $parts) {
                return [$offset, $offset + count($parts) - 1];
            }
        }

        return in_array($phrase, $attached, true) ? [-1, -1] : null;
    }

    /** @param list<string> $tokens @param list<string> $requirements @param list<string> $exclusions @param array{0:int,1:int} $span */
    private function contextAllows(array $tokens, array $requirements, array $exclusions, array $span): bool
    {
        foreach ($requirements as $requirement) {
            if ($requirement === '__pharma_indicator__' ? ! $this->hasNearbyIndicator($tokens, $span) : ! in_array($requirement, $tokens, true)) {
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

    /** @param list<string> $tokens @param array{0:int,1:int} $span @param array<string, mixed> $context */
    private function hasCorroboration(array $tokens, array $span, array $context, string $label): bool
    {
        $form = isset($context['form']) ? $this->normalizer->normalize((string) $context['form'])['normalized'] : '';

        return ! empty($context['dosage']) || ! empty($context['unit']) || str_contains($form, mb_strtolower($label)) || $this->hasNearbyIndicator($tokens, $span);
    }

    /** @param list<string> $tokens @param array{0:int,1:int} $span */
    private function hasNearbyIndicator(array $tokens, array $span): bool
    {
        $nearby = array_slice($tokens, max(0, $span[0] - 3), max(1, $span[1] - $span[0] + 7));

        return collect($nearby)->contains(fn (string $token): bool => preg_match('/^\d/u', $token) === 1 || in_array($token, ['мг', 'мл', 'г', 'кг', 'шт', 'уп', 'упак', 'пак', 'саше', 'доза', 'табл', 'капс'], true));
    }

    /** @param list<array<string, mixed>> $matches @return array{list<array<string, mixed>>, bool} */
    private function applySupersedence(array $matches): array
    {
        $suppressed = [];
        $conflict = false;
        foreach ($matches as $match) {
            foreach ($matches as $candidate) {
                $forward = in_array($candidate['label'], $match['supersedes'], true);
                $reverse = in_array($match['label'], $candidate['supersedes'], true);
                $conflict = $conflict || ($forward && $reverse);
                if ($forward && ! $reverse) {
                    $suppressed[$candidate['code']] = $match['code'];
                }
            }
        }
        foreach ($matches as &$match) {
            if (isset($suppressed[$match['code']])) {
                $match['decision'] = 'suppressed';
                $match['suppressed_by'] = $suppressed[$match['code']];
            }
        }

        return [$matches, $conflict];
    }

    /** @param list<array<string, mixed>> $matches */
    private function hasConflictingSpan(array $matches): bool
    {
        foreach ($matches as $index => $left) {
            foreach (array_slice($matches, $index + 1) as $right) {
                if ($left['span'] === $right['span']) {
                    return true;
                }
            }
        }

        return false;
    }

    private function categoryOrder(string $label): int
    {
        $position = array_search($label, array_map(fn (ProductCategory $category): string => $category->value, ProductCategory::ordered()), true);

        return $position === false ? PHP_INT_MAX : $position;
    }
}
