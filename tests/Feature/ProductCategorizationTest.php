<?php

namespace Tests\Feature;

use App\Enums\ProductCategory;
use App\Services\PriceList\ProductCategoryClassifier;
use App\Services\PriceList\ProductCategoryRuleSetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCategorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_classifies_complete_tokens_and_preserves_unicode_source_normalization(): void
    {
        $ruleSet = app(ProductCategoryRuleSetResolver::class)->current();
        $result = app(ProductCategoryClassifier::class)->classify('  Сироп100мл—для детей ', $ruleSet);

        $this->assertSame(ProductCategory::Syrup->value, $result['category']);
        $this->assertSame('matched', $result['status']);
        $this->assertSame('сироп100мл-для детей', $result['normalized']);
    }

    public function test_it_does_not_match_unsafe_substrings(): void
    {
        $ruleSet = app(ProductCategoryRuleSetResolver::class)->current();

        foreach (['Сирдалуд', 'Сириус', 'Алмагель', 'Гелмадол', 'Гельминтокс', 'ампициллин', 'памперс', 'тампон', 'таблетница'] as $name) {
            $this->assertSame(ProductCategory::Unrecognized->value, app(ProductCategoryClassifier::class)->classify($name, $ruleSet)['category']);
        }
    }

    public function test_specific_injection_rule_supersedes_solution(): void
    {
        $ruleSet = app(ProductCategoryRuleSetResolver::class)->current();
        $result = app(ProductCategoryClassifier::class)->classify('Раствор для инъекций 2 мл', $ruleSet);

        $this->assertSame(ProductCategory::Injections->value, $result['category']);
        $this->assertSame('matched', $result['status']);
    }

    public function test_contextual_past_requires_a_following_pharmaceutical_indicator(): void
    {
        $ruleSet = app(ProductCategoryRuleSetResolver::class)->current();

        $this->assertSame(ProductCategory::Unrecognized->value, app(ProductCategoryClassifier::class)->classify('Паст для рук', $ruleSet)['category']);
        $this->assertSame(ProductCategory::Lozenges->value, app(ProductCategoryClassifier::class)->classify('Паст. 24 шт', $ruleSet)['category']);
        $this->assertSame(ProductCategory::Paste->value, app(ProductCategoryClassifier::class)->classify('Паста 20 г', $ruleSet)['category']);
    }
}
