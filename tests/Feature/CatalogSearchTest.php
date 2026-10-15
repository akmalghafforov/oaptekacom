<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PharmacySupplierDiscount;
use App\Models\PriceListImport;
use App\Models\ProductCategory;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Pagination\Cursor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('highlightedSearchTerms')]
    public function test_search_highlights_literal_name_matches_in_all_result_layouts(string $query, string $name, array $matches): void
    {
        $user = $this->pharmacyUser();
        [$supplier, $import] = $this->activeImport();
        $this->offer($supplier, $import, $name, mb_strtolower($name), 12, 1);

        $response = $this->actingAs($user)->getJson(route('catalog.search', ['q' => $query, 'view' => 'list', 'substring_match' => 1]))->assertOk();

        foreach (['desktop_rows', 'mobile_rows', 'cards'] as $fragment) {
            $html = $response->json('fragments.'.$fragment);
            preg_match_all('/<mark[^>]*>(.*?)<\/mark>/s', $html, $highlighted);
            $this->assertSame($matches, $highlighted[1]);
            $this->assertStringNotContainsString('<script>', $html);
        }
    }

    public static function highlightedSearchTerms(): array
    {
        return [
            'cyrillic ignores case and highlights every occurrence' => ['тиКаГ', 'Тикагрелор ТИКАГ 90мг', ['Тикаг', 'ТИКАГ']],
            'regex punctuation is literal' => ['Амо+', 'Амо+ таблетки <script>alert(1)</script>', ['Амо+']],
            'empty search has no highlights' => ['', 'Амоксициллин', []],
            'matching suffix' => ['таблетки', 'Амоксициллин таблетки', ['таблетки']],
        ];
    }

    public function test_active_pharmacy_and_wholesaler_can_search_while_guest_and_inactive_pharmacy_cannot(): void
    {
        $pharmacy = $this->pharmacyUser();
        $wholesaler = User::factory()->wholesaler()->create();
        $inactive = User::factory()->pharmacy()->create();
        [$supplier, $import] = $this->activeImport();
        $this->offer($supplier, $import, 'Амоксициллин', 'амоксициллин capsule', 12, 1);

        $response = $this->actingAs($pharmacy)->getJson(route('catalog.search', ['q' => '  АМО  ']))->assertOk();
        $this->assertStringContainsString('Амоксициллин', $response->json('html'));
        $this->actingAs($wholesaler)->getJson(route('catalog.search', ['q' => 'амо']))->assertOk();
        auth()->logout();
        $this->get(route('catalog.search', ['q' => 'амо']))->assertRedirect(route('login'));
        $this->actingAs($inactive)->get(route('catalog.search', ['q' => 'амо']))->assertRedirect(route('subscription.create'));
    }

    #[DataProvider('invalidQueries')]
    public function test_returns_422_for_invalid_query_parameters(array $query, string $field): void
    {
        $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', $query))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidQueries(): array
    {
        return [
            'trimmed short query' => [['q' => ' аб '], 'q'],
            'substring string' => [['substring_match' => 'true'], 'substring_match'],
            'substring integer' => [['substring_match' => 2], 'substring_match'],
            'substring array' => [['substring_match' => [1]], 'substring_match'],
            'substring empty' => [['substring_match' => ''], 'substring_match'],
            'query array' => [['q' => ['асп']], 'q'],
            'invalid category' => [['q' => 'асп', 'category' => 999999], 'category'],
            'city array' => [['q' => 'асп', 'city' => ['Душанбе']], 'city'],
            'invalid price range' => [['q' => 'асп', 'min_price' => 20, 'max_price' => 10], 'max_price'],
            'malformed cursor' => [['q' => 'асп', 'cursor' => 'not-a-cursor'], 'cursor'],
            'cursor array' => [['q' => 'асп', 'cursor' => ['invalid']], 'cursor'],
            'cursor missing columns' => [['q' => 'асп', 'cursor' => (new Cursor(['other' => 1]))->encode()], 'cursor'],
        ];
    }

    public function test_matches_cyrillic_and_latin_substrings_and_treats_wildcards_literally(): void
    {
        [$supplier, $import] = $this->activeImport();
        $this->offer($supplier, $import, 'Парацетамол Forte', 'парацетамол forte 10%_mix', 10, 1);
        $user = $this->pharmacyUser();

        $this->assertStringContainsString('Парацетамол Forte', $this->actingAs($user)->getJson(route('catalog.search', ['substring_match' => 1, 'q' => 'РАЦ']))->json('html'));
        $this->assertStringContainsString('Парацетамол Forte', $this->actingAs($user)->getJson(route('catalog.search', ['substring_match' => 1, 'q' => 'FOR']))->json('html'));
        $this->assertStringContainsString('Парацетамол Forte', $this->actingAs($user)->getJson(route('catalog.search', ['substring_match' => 1, 'q' => '10%']))->json('html'));
        $this->actingAs($user)->getJson(route('catalog.search', ['substring_match' => 1, 'q' => '10_']))->assertJsonPath('html', '');
    }

    #[DataProvider('nameMatchingQueries')]
    public function test_name_matching_mode_is_consistent_for_results_totals_and_facets(string $query, array $mode, int $expected): void
    {
        [$supplier, $import] = $this->activeImport();
        $category = ProductCategory::query()->where('is_active', true)->firstOrFail();
        $offer = $this->offer($supplier, $import, 'Tester Парацетамол', 'tester парацетамол', 10, 1);
        $offer->medicine->categories()->sync([$category->id => ['source' => 'test', 'confidence' => 100]]);

        $response = $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', [
            'q' => $query, 'view' => 'list', ...$mode,
        ]));

        $response->assertOk()->assertJsonPath('pagination.returned', $expected)
            ->assertJsonPath('pagination.total', $expected)->assertJsonPath('facets.all_count', $expected);
        $this->assertSame($expected === 1 ? [$offer->id] : [], $this->offerIds($response->json('html')));
        $this->assertSame($expected === 1 ? [['id' => $category->id, 'count' => 1]] : [],
            collect($response->json('facets.categories'))->map(fn (array $facet): array => ['id' => $facet['id'], 'count' => $facet['count']])->all());
        $this->assertSame($expected, count($response->json('facets.cities')));
        $this->assertSame($expected, count($response->json('facets.suppliers')));
    }

    public static function nameMatchingQueries(): array
    {
        return [
            'default Latin prefix' => ['tEs', [], 1],
            'disabled Latin prefix' => ['tEs', ['substring_match' => 0], 1],
            'default Latin substring' => ['EsT', [], 0],
            'disabled Latin substring' => ['EsT', ['substring_match' => '0'], 0],
            'enabled Latin prefix' => ['tEs', ['substring_match' => 1], 1],
            'enabled Latin substring' => ['EsT', ['substring_match' => '1'], 1],
            'default later word' => ['ПаРа', [], 0],
            'enabled later word' => ['ПаРа', ['substring_match' => 1], 1],
            'default Cyrillic substring' => ['РаЦ', [], 0],
            'enabled Cyrillic substring' => ['РаЦ', ['substring_match' => 1], 1],
        ];
    }

    #[DataProvider('cyrillicMatchingModes')]
    public function test_cyrillic_prefix_is_case_insensitive(array $mode): void
    {
        [$supplier, $import] = $this->activeImport();
        $this->offer($supplier, $import, 'Парацетамол', 'парацетамол', 10, 1);

        $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', ['q' => 'пАрА', ...$mode]))
            ->assertOk()->assertJsonPath('pagination.total', 1);
    }

    public static function cyrillicMatchingModes(): array
    {
        return ['prefix' => [[]], 'substring' => [['substring_match' => 1]]];
    }

    #[DataProvider('literalQueries')]
    public function test_wildcard_and_backslash_queries_are_literal(string $query, array $mode, int $expected): void
    {
        [$supplier, $import] = $this->activeImport();
        $this->offer($supplier, $import, '10%_\\mix', '10%_\\mix', 10, 1);
        $this->offer($supplier, $import, '10abcXmix', '10abcxmix', 11, 2);

        $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', ['q' => $query, ...$mode]))
            ->assertOk()->assertJsonPath('pagination.total', $expected);
    }

    public static function literalQueries(): array
    {
        return [
            'prefix percent' => ['10%', [], 1],
            'substring percent' => ['10%', ['substring_match' => 1], 1],
            'prefix underscore mismatch' => ['10_', [], 0],
            'substring underscore mismatch' => ['10_', ['substring_match' => 1], 0],
            'prefix backslash' => ['10%_\\m', [], 1],
            'substring backslash' => ['%_\\m', ['substring_match' => 1], 1],
            'later literal excludes prefix' => ['%_\\m', [], 0],
        ];
    }

    public function test_category_filter_and_facets_count_offer_rows_without_applying_selected_category(): void
    {
        [$supplier, $import] = $this->activeImport();
        $categories = ProductCategory::query()->where('is_active', true)->orderBy('sort_order')->limit(2)->get();
        $first = $this->offer($supplier, $import, 'Тест лекарство один', 'тест лекарство один', 10, 1);
        $second = $this->offer($supplier, $import, 'Тест лекарство два', 'тест лекарство два', 11, 2);
        $pivot = ['source' => 'test', 'confidence' => 100];
        $first->medicine->categories()->sync([$categories[0]->id => $pivot, $categories[1]->id => $pivot]);
        $second->medicine->categories()->sync([$categories[0]->id => $pivot]);

        $response = $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', ['q' => 'тест', 'category' => $categories[1]->id]));

        $response->assertOk();
        $this->assertStringContainsString('Тест лекарство один', $response->json('html'));
        $this->assertStringNotContainsString('Тест лекарство два', $response->json('html'));
        $this->assertSame([$categories[0]->id, $categories[1]->id], collect($response->json('facets'))->pluck('id')->all());
        $this->assertSame([2, 1], collect($response->json('facets'))->pluck('count')->all());
    }

    public function test_catalog_page_renders_compact_toolbar_with_accessible_views_and_category_images(): void
    {
        $categories = ProductCategory::query()->where('is_active', true)->orderBy('sort_order')->get();

        $response = $this->actingAs($this->pharmacyUser())->get(route('catalog'));

        $response
            ->assertSee('data-catalog-toolbar', false)
            ->assertSee('aria-label="Список"', false)
            ->assertSee('aria-label="Карточки"', false)
            ->assertSee('aria-label="Поставщики"', false)
            ->assertSee('data-all-count', false);

        $this->assertSame($categories->count() * 2 + 3, substr_count($response->getContent(), 'data-category-image'));
        $this->assertSame($categories->count(), substr_count($response->getContent(), 'data-category-count'));

        foreach ($categories as $category) {
            $response->assertSee('data-category="'.$category->id.'"', false);
        }
    }

    public function test_mobile_picker_renders_all_active_categories_in_configured_order(): void
    {
        ProductCategory::query()->create(['code' => 'hidden_picker', 'label' => 'Скрытая категория', 'sort_order' => -2, 'is_active' => false]);
        $categories = ProductCategory::query()->where('is_active', true)->orderBy('sort_order')->get();

        $response = $this->actingAs($this->pharmacyUser())->get(route('catalog'));

        $response->assertSee('aria-controls="catalog-categories"', false)->assertSee('Категории товаров')->assertSee('Все категории');
        preg_match_all('/data-mobile-category="([^"]*)"/', $response->getContent(), $matches);
        $this->assertSame(['', ...$categories->map(fn (ProductCategory $category): string => (string) $category->id)->all()], $matches[1]);
        $response->assertDontSee('Скрытая категория');
    }

    public function test_catalog_page_renders_compact_search_panel_with_accessible_controls(): void
    {
        $response = $this->actingAs($this->pharmacyUser())->get(route('catalog'));

        $response
            ->assertSee('Искать в любой части названия')
            ->assertSee('name="substring_match"', false)
            ->assertSee('for="substring_match"', false)
            ->assertSee('form="catalog-search-form"', false)
            ->assertSee('data-search-panel', false)
            ->assertSee('data-active-category-label', false)
            ->assertSee('data-category-label', false)
            ->assertDontSee('data-search-actions', false)
            ->assertDontSee('AI-закупка')
            ->assertSee('aria-label="Фильтры"', false)
            ->assertSee('aria-label="Найти"', false)
            ->assertSee('Название лекарства')
            ->assertDontSee('Оптовый каталог')
            ->assertDontSee('Оставьте поле пустым, чтобы посмотреть весь каталог.');
    }

    #[DataProvider('checkboxStates')]
    public function test_catalog_checkbox_is_unchecked_by_default_and_restored_from_url(array $mode, bool $checked): void
    {
        $response = $this->actingAs($this->pharmacyUser())->get(route('catalog', $mode));
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $checkbox = $xpath->query('//input[@name="substring_match"]')->item(0);

        $this->assertNotNull($checkbox);
        $this->assertSame('checkbox', $checkbox->getAttribute('type'));
        $this->assertSame('1', $checkbox->getAttribute('value'));
        $this->assertSame('catalog-search-form', $checkbox->getAttribute('form'));
        $this->assertSame($checked, $checkbox->hasAttribute('checked'));
        $this->assertSame('Искать в любой части названия', trim($xpath->query('//label[@for="substring_match"]/span')->item(0)->textContent));
    }

    public static function checkboxStates(): array
    {
        return ['default' => [[], false], 'disabled' => [['substring_match' => 0], false], 'enabled' => [['substring_match' => 1], true]];
    }

    public function test_catalog_page_uses_neutral_medicine_image_for_unmapped_category_code(): void
    {
        $category = ProductCategory::query()->create([
            'code' => 'new_unknown_form',
            'label' => 'Новая форма',
            'sort_order' => 999,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->pharmacyUser())->get(route('catalog'));

        $response->assertSee('Новая форма');
        $this->assertSame(1, preg_match('/data-category="'.$category->id.'".*?src="[^"]*images\/catalog\/categories\/medicine\.webp".*?width="32".*?height="32"/s', $response->getContent()));
    }

    public function test_current_catalog_includes_expired_offers_and_excludes_superseded_imports(): void
    {
        [$supplier, $activeImport] = $this->activeImport();
        $oldImport = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Superseded]);
        $this->offer($supplier, $activeImport, 'Нулевой остаток', 'проверка нулевой остаток', 10, 1, ['quantity' => 0]);
        $this->offer($supplier, $activeImport, 'Сегодня истекает', 'проверка сегодня истекает', 11, 2, ['expires_at' => now('Asia/Dushanbe')->toDateString()]);
        $this->offer($supplier, $activeImport, 'Просрочено', 'проверка просрочено', 12, 3, ['expires_at' => now('Asia/Dushanbe')->subDay()->toDateString()]);
        $this->offer($supplier, $oldImport, 'Старая загрузка', 'проверка старая загрузка', 13, 1);

        $response = $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', ['q' => 'проверка']));

        $this->assertStringContainsString('Нулевой остаток', $response->json('html'));
        $this->assertStringContainsString('Сегодня истекает', $response->json('html'));
        $this->assertStringContainsString('Просрочено', $response->json('html'));
        $this->assertStringNotContainsString('Старая загрузка', $response->json('html'));
    }

    public function test_catalog_fragments_render_expired_known_and_missing_expiration_dates(): void
    {
        $this->travelTo('2026-09-15 12:00:00');
        [$supplier, $import] = $this->activeImport();
        $this->offer($supplier, $import, 'Просроченный', 'проверка срока', 10, 1, ['expires_at' => '2026-09-14']);
        $this->offer($supplier, $import, 'С указанным сроком', 'проверка срока', 11, 2, ['expires_at' => '2027-01-15']);
        $this->offer($supplier, $import, 'Без срока', 'проверка срока', 12, 3);

        $response = $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', ['q' => 'проверка', 'view' => 'list']))->assertOk();

        foreach (['desktop_rows', 'mobile_rows', 'cards'] as $fragment) {
            $html = $response->json('fragments.'.$fragment);
            $this->assertStringContainsString('Срок годности истёк: 14.09.2026', $html);
            $this->assertStringContainsString('15.01.2027', $html);
            $this->assertStringNotContainsString('Неуказан', $html);
        }
    }

    public function test_list_search_returns_dedicated_mobile_rows_without_changing_existing_fragments(): void
    {
        [$supplier, $import] = $this->activeImport();
        $offer = $this->offer($supplier, $import, 'Мобильный парацетамол', 'мобильный парацетамол', 14, 1);

        $response = $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', ['q' => 'мобильный', 'view' => 'list']));

        $response
            ->assertOk()
            ->assertJsonPath('pagination.returned', 1);
        $this->assertStringContainsString('data-mobile-offer-row', $response->json('fragments.mobile_rows'));
        $this->assertStringContainsString('data-mobile-result-shell="list"', $response->json('fragments.mobile_rows'));
        $this->assertStringContainsString('data-offer-id="'.$offer->id.'"', $response->json('fragments.mobile_rows'));
        $this->assertStringContainsString('type="submit"', $response->json('fragments.mobile_rows'));
        $this->assertStringContainsString('data-cart-submit', $response->json('fragments.mobile_rows'));
        $this->assertStringContainsString('aria-label="Добавить в корзину"', $response->json('fragments.mobile_rows'));
        $this->assertStringContainsString('<tr', $response->json('fragments.desktop_rows'));
        $this->assertStringContainsString('catalog-offer-card', $response->json('fragments.cards'));
        $this->assertStringContainsString('data-mobile-result-shell="grid"', $response->json('fragments.cards'));
    }

    #[DataProvider('suppliedMobileForms')]
    public function test_mobile_list_keeps_supplied_form_and_limits_missing_form_and_added_dates_to_tablet(?string $dosage, ?string $form, string $expectedForm): void
    {
        [$supplier, $import] = $this->activeImport();
        $supplied = $this->offer($supplier, $import, 'С формой', 'мобильная мета', 10, 1);
        $supplied->medicine->update(['dosage' => $dosage, 'form' => $form]);
        $missing = $this->offer($supplier, $import, 'Без формы', 'мобильная мета', 11, 2);
        $missing->medicine->update(['dosage' => null, 'form' => null]);

        $response = $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', ['q' => 'мобильная мета', 'view' => 'list']));

        $html = $response->json('fragments.mobile_rows');
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $suppliedForm = $xpath->query('//p[contains(., "'.$expectedForm.'")]')->item(0);
        $missingForm = $xpath->query('//p[contains(., "Форма не указана")]')->item(0);
        $addedDates = $xpath->query('//p[contains(., "Добавлен в систему")]');
        $this->assertNotNull($suppliedForm);
        $this->assertStringNotContainsString('hidden', $suppliedForm->getAttribute('class'));
        $this->assertNotNull($missingForm);
        $this->assertStringContainsString('hidden md:block', $missingForm->getAttribute('class'));
        $this->assertCount(2, $addedDates);
        foreach ($addedDates as $addedDate) {
            $this->assertStringContainsString('hidden', $addedDate->getAttribute('class'));
            $this->assertStringContainsString('md:block', $addedDate->getAttribute('class'));
        }
        $this->assertSame(0, $xpath->query('//*[contains(@class, "mobile-offer-expiry")]')->length);
        $this->assertStringContainsString(e($supplier->name), $html);
        $this->assertStringContainsString('Душанбе', $html);
    }

    /** @return array<string, array{?string, ?string, string}> */
    public static function suppliedMobileForms(): array
    {
        return [
            'dosage and form' => ['500 мг', 'Капсулы', '500 мг · Капсулы'],
            'dosage only' => ['500 мг', null, '500 мг'],
            'form only' => [null, 'Капсулы', 'Капсулы'],
        ];
    }

    public function test_existing_cart_items_render_persistent_in_cart_controls_in_every_catalog_fragment(): void
    {
        [$supplier, $import] = $this->activeImport();
        $offer = $this->offer($supplier, $import, 'Корзина парацетамол', 'корзина парацетамол', 14, 1);
        $user = $this->pharmacyUser();
        $cart = Cart::create(['user_id' => $user->id]);
        $item = CartItem::create([
            'cart_id' => $cart->id,
            'offer_id' => $offer->id,
            'quantity' => 3,
            'unit_price' => '14.00',
            'snapshot' => [],
        ]);

        $response = $this->actingAs($user)->getJson(route('catalog.search', ['q' => 'корзина', 'view' => 'list']))->assertOk();

        foreach (['desktop_rows', 'mobile_rows', 'cards'] as $fragment) {
            $html = $response->json('fragments.'.$fragment);
            $this->assertStringContainsString('aria-label="Товар в корзине: 3 шт."', $html);
            $this->assertStringContainsString('data-cart-quantity>3</span>', $html);
            $this->assertStringContainsString('data-cart-remove-form', $html);
            $this->assertStringContainsString('action="'.route('cart.items.destroy', $item).'"', $html);
            $this->assertStringNotContainsString('Добавить ещё', $html);
        }
    }

    public function test_non_list_search_keeps_mobile_and_desktop_row_fragments_null(): void
    {
        [$supplier, $import] = $this->activeImport();
        $this->offer($supplier, $import, 'Сеточный парацетамол', 'сеточный парацетамол', 14, 1);

        $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', ['q' => 'сеточный', 'view' => 'grid']))
            ->assertOk()
            ->assertJsonPath('fragments.mobile_rows', null)
            ->assertJsonPath('fragments.desktop_rows', null);
    }

    public function test_supplier_search_renders_mobile_supplier_actions(): void
    {
        [$supplier, $import] = $this->activeImport();
        $this->offer($supplier, $import, 'Мобильный поставщик', 'мобильный поставщик', 14, 1);

        $response = $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', ['q' => 'мобильный', 'view' => 'suppliers']));

        $this->assertStringContainsString('data-mobile-result-shell="suppliers"', $response->json('fragments.supplier_cards'));
        $this->assertStringContainsString('data-supplier-open="'.$supplier->id.'"', $response->json('fragments.supplier_cards'));
        $this->assertStringContainsString('Информация', $response->json('fragments.supplier_cards'));
    }

    public function test_cursor_pages_are_stably_ordered_without_gaps_or_duplicates(): void
    {
        [$supplier, $import] = $this->activeImport();
        $offers = collect();
        foreach (range(1, 55) as $row) {
            $offers->push($this->offer($supplier, $import, "Пагинация {$row}", "пагинация {$row}", $row <= 3 ? 10 : $row, $row));
        }
        $expected = $offers->sort(fn (Offer $a, Offer $b): int => [(float) $a->price, $a->id] <=> [(float) $b->price, $b->id])->pluck('id')->values()->all();
        $user = $this->pharmacyUser();

        $first = $this->actingAs($user)->getJson(route('catalog.search', ['q' => 'пагинация']))->assertOk()->assertJsonPath('has_more', true);
        $firstIds = $this->offerIds($first->json('html'));
        $second = $this->actingAs($user)->getJson(route('catalog.search', ['q' => 'пагинация', 'cursor' => $first->json('next_cursor')]))->assertOk()->assertJsonPath('facets', null);
        $actual = [...$firstIds, ...$this->offerIds($second->json('html'))];

        $this->assertCount(50, $firstIds);
        $this->assertSame($expected, $actual);
        $this->assertSame($actual, array_values(array_unique($actual)));
    }

    #[DataProvider('rankedSorts')]
    public function test_literal_results_precede_fuzzy_results_across_cursor_pages(string $sort): void
    {
        config(['catalog.page_size' => 2]);
        $this->freezeTime();
        [$supplier, $import] = $this->activeImport();
        $fuzzy = $this->offer($supplier, $import, 'Амлозопин', 'амлозопин 10 мг', 1, 1);
        $exact = $this->offer($supplier, $import, 'Амлозипин', 'амлозипин 10 мг', 100, 2);
        $exactSecond = $this->offer($supplier, $import, 'Амлозипин 20 мг', 'амлозипин 20 мг', 100, 3);
        $user = $this->pharmacyUser();
        PharmacySupplierDiscount::create(['pharmacy_organization_id' => $user->activeOrganizationId(), 'supplier_organization_id' => $supplier->id, 'supplier_discount_percent' => '50.00']);
        $filters = ['q' => 'Амлозипин', 'sort' => $sort, 'view' => 'list'];

        $first = $this->actingAs($user)->getJson(route('catalog.search', $filters))
            ->assertOk()->assertJsonPath('pagination.total', 3)->assertJsonPath('facets.all_count', 3);
        $expected = in_array($sort, ['price_desc', 'updated_desc']) ? [$exactSecond->id, $exact->id] : [$exact->id, $exactSecond->id];
        $this->assertSame($expected, $this->offerIds($first->json('html')));
        $next = $this->getJson(route('catalog.search', [...$filters, 'cursor' => $first->json('next_cursor')]))->assertOk();
        $this->assertSame([$fuzzy->id], $this->offerIds($next->json('html')));
        $this->assertFalse($next->json('has_more'));
    }

    public static function rankedSorts(): array
    {
        return ['ascending price' => ['price_asc'], 'descending price' => ['price_desc'], 'name' => ['name_asc'], 'updated' => ['updated_desc']];
    }

    public function test_fuzzy_matches_share_filters_facets_and_current_import_scope(): void
    {
        [$supplier, $import] = $this->activeImport();
        $category = ProductCategory::query()->where('is_active', true)->firstOrFail();
        $offer = $this->offer($supplier, $import, 'Fuzzy', 'zzамлозопин details', 10, 1);
        $offer->medicine->categories()->sync([$category->id => ['source' => 'test', 'confidence' => 100]]);
        $oldImport = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Superseded]);
        $this->offer($supplier, $oldImport, 'Old', 'амлозопин', 10, 1);
        $filters = ['q' => 'амлозипин', 'substring_match' => 1, 'view' => 'list', 'category' => $category->id, 'cities' => ['Душанбе'], 'suppliers' => [$supplier->id], 'min_price' => 5, 'max_price' => 15];

        $response = $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', $filters))
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('facets.categories.0.count', 1);
        $this->assertSame([$offer->id], $this->offerIds($response->json('html')));
        $this->assertCount(1, $response->json('facets.cities'));
        $this->assertCount(1, $response->json('facets.suppliers'));
        $this->getJson(route('catalog.search', [...$filters, 'min_price' => 11]))->assertOk()->assertJsonPath('pagination.total', 0);
    }

    #[DataProvider('fuzzyCandidateCases')]
    public function test_candidate_filter_preserves_valid_edits(string $query, string $text, bool $substring): void
    {
        [$supplier, $import] = $this->activeImport();
        $offer = $this->offer($supplier, $import, 'Candidate', $text, 10, 1);
        $response = $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', ['q' => $query, 'substring_match' => (int) $substring]))
            ->assertOk()->assertJsonPath('pagination.total', 1);
        $this->assertSame([$offer->id], $this->offerIds($response->json('html')));
    }

    public static function fuzzyCandidateCases(): array
    {
        return [
            'first replacement' => ['abcde', 'xbcde', false],
            'middle insertion' => ['abcde', 'abxcde', false],
            'middle deletion' => ['abcde', 'abde', false],
            'Cyrillic' => ['амлозипин', 'амлозопин', false],
            'substring' => ['abcde', 'zzabxde', true],
            'two edits and swap' => ['abcdefghijklmnopqrst', 'bacdefghijklmnopqrst', false],
            'escaped segment' => ['ab%_cd', 'xb%_cd', false],
        ];
    }

    public function test_old_search_cursor_without_rank_returns_validation_error(): void
    {
        $cursor = (new Cursor(['effective_price' => 10, 'id' => 1]))->encode();
        $this->actingAs($this->pharmacyUser())->getJson(route('catalog.search', ['q' => 'abcde', 'cursor' => $cursor]))
            ->assertUnprocessable()->assertJsonValidationErrors('cursor');
    }

    private function pharmacyUser(): User
    {
        $user = User::factory()->pharmacy()->create();
        Subscription::factory()->for($user)->create();

        return $user;
    }

    /** @return array{Organization, PriceListImport} */
    private function activeImport(): array
    {
        $supplier = Organization::factory()->wholesaler()->create(['city' => 'Душанбе']);
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $supplier->update(['active_price_list_import_id' => $import->id]);

        return [$supplier, $import];
    }

    /** @param array<string, mixed> $attributes */
    private function offer(Organization $supplier, PriceListImport $import, string $name, string $searchText, float|int $price, int $row, array $attributes = []): Offer
    {
        $medicine = Medicine::factory()->create(['supplier_organization_id' => $supplier->id, 'name' => $name, 'normalized_name' => mb_strtolower($name), 'search_text' => $searchText]);

        return Offer::factory()->for($supplier, 'organization')->for($medicine)->create([...$attributes, 'price_list_import_id' => $import->id, 'price' => $price, 'source_row' => $row]);
    }

    /** @return array<int, int> */
    private function offerIds(string $html): array
    {
        preg_match_all('/data-offer-id="(\d+)"/', $html, $matches);

        return array_map('intval', $matches[1]);
    }
}
