<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Models\CartItem;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CatalogCartSearchRegressionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_another_product_can_be_added_after_search_add_remove_and_clearing_search(): void
    {
        [$offer, $search] = $this->searchAfterAddingAndRemovingFirstProduct(20);
        $this->assertOfferControls($search, $offer, 0);

        $this->postJson(route('cart.add', $offer), ['quantity' => 10])->assertOk()
            ->assertJsonPath('item.offer_id', $offer->id)->assertJsonPath('item.quantity', 10)
            ->assertJsonPath('cart.total_quantity', 10);

        $this->assertDatabaseHas('cart_items', ['offer_id' => $offer->id, 'quantity' => 10]);
        $this->assertDatabaseCount('cart_items', 1);
        $search = $this->getJson(route('catalog.search', ['q' => 'Тимоло', 'view' => 'list']))->assertOk()
            ->assertJsonPath('cart.total_quantity', 10);
        $this->assertOfferControls($search, $offer, 10);
    }

    public function test_zero_stock_second_product_has_visible_feedback_and_returns_404_without_insertion(): void
    {
        [$offer, $search] = $this->searchAfterAddingAndRemovingFirstProduct(0);
        $this->assertOfferControls($search, $offer, 0, 'Нет в наличии у поставщика');

        $this->postJson(route('cart.add', $offer), ['quantity' => 10])->assertNotFound()
            ->assertJsonPath('message', 'Предложение недоступно. Проверьте наличие у поставщика.');

        $this->assertDatabaseCount('cart_items', 0);
    }

    #[TestWith([0.5, 'Недостаточно остатка для заказа'])]
    #[TestWith([5, null])]
    public function test_insufficient_second_product_stock_returns_422_without_insertion(float $stock, ?string $explanation): void
    {
        [$offer, $search] = $this->searchAfterAddingAndRemovingFirstProduct($stock);
        $this->assertOfferControls($search, $offer, 0, $explanation);

        $this->postJson(route('cart.add', $offer), ['quantity' => 10])->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'Запрошенное количество превышает остаток поставщика.']);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_catalog_explains_when_purchasing_is_unavailable_in_the_account_mode(): void
    {
        [$offer] = $this->searchAfterAddingAndRemovingFirstProduct(20);
        $user = User::factory()->wholesaler()->create();

        $search = $this->actingAs($user)->getJson(route('catalog.search', ['q' => 'Тимоло', 'view' => 'list']))->assertOk();

        $this->assertOfferControls($search, $offer, 0, 'Добавление в корзину недоступно в текущем режиме');
        $this->postJson(route('cart.add', $offer), ['quantity' => 10])->assertNotFound()
            ->assertJsonPath('message', 'Добавление в корзину недоступно в текущем режиме');
        $this->assertDatabaseCount('cart_items', 0);
    }

    /** @return array{Offer, TestResponse} */
    private function searchAfterAddingAndRemovingFirstProduct(float $secondStock): array
    {
        $user = User::factory()->pharmacy()->create();
        Subscription::factory()->for($user)->create();
        $supplier = Organization::factory()->wholesaler()->create(['name' => 'Азия Фарм']);
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $supplier->update(['active_price_list_import_id' => $import->id]);
        $first = $this->offer($supplier, $import, 'Ко-амл 5/5 мг', 1, 20, 10);
        $second = $this->offer($supplier, $import, 'Тимолол 0,5%', 2, $secondStock, 10);
        $alternative = $this->offer($supplier, $import, 'Тимолол 0,25%', 3, 20, 20);
        $this->actingAs($user);

        $search = $this->getJson(route('catalog.search', ['q' => 'Ко-амл', 'view' => 'list']))->assertOk();
        $this->assertOfferIds($search, [$first->id]);
        $this->postJson(route('cart.add', $first), ['quantity' => 10])->assertOk()
            ->assertJsonPath('item.offer_id', $first->id)->assertJsonPath('item.quantity', 10)
            ->assertJsonPath('cart.total_quantity', 10);
        $item = CartItem::query()->where('offer_id', $first->id)->sole();
        $this->assertSame(10, $item->quantity);
        $search = $this->getJson(route('catalog.search', ['q' => 'Ко-амл', 'view' => 'list']))->assertOk();
        $this->assertOfferControls($search, $first, 10);
        $this->deleteJson(route('cart.items.destroy', $item))->assertOk()
            ->assertJsonPath('item.offer_id', $first->id)->assertJsonPath('cart.total_quantity', 0);
        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
        $cleared = $this->getJson(route('catalog.search', ['q' => '', 'view' => 'list']))->assertOk()
            ->assertJsonPath('cart.total_quantity', 0);
        $this->assertOfferControls($cleared, $first, 0);
        $search = $this->getJson(route('catalog.search', ['q' => 'Тимоло', 'view' => 'list']))->assertOk();
        $this->assertOfferIds($search, [$second->id, $alternative->id]);

        return [$second, $search];
    }

    private function offer(Organization $supplier, PriceListImport $import, string $name, int $row, float $stock, int $price): Offer
    {
        $medicine = Medicine::factory()->create([
            'supplier_organization_id' => $supplier->id, 'name' => $name,
            'normalized_name' => mb_strtolower($name), 'search_text' => mb_strtolower($name),
        ]);

        return Offer::factory()->for($supplier, 'organization')->for($medicine)->create([
            'price_list_import_id' => $import->id, 'source_row' => $row, 'quantity' => $stock, 'price' => $price,
        ]);
    }

    /** @param list<int> $ids */
    private function assertOfferIds(TestResponse $response, array $ids): void
    {
        foreach (['desktop_rows', 'mobile_rows', 'cards'] as $fragment) {
            preg_match_all('/data-offer-id="(\d+)"/', $response->json('fragments.'.$fragment), $matches);
            $this->assertSame($ids, array_map('intval', $matches[1]));
        }
    }

    private function assertOfferControls(TestResponse $response, Offer $offer, int $quantity, ?string $explanation = null): void
    {
        foreach (['desktop_rows', 'mobile_rows', 'cards'] as $fragment) {
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$response->json('fragments.'.$fragment));
            $xpath = new \DOMXPath($document);
            $row = $xpath->query('//*[@data-offer-id="'.$offer->id.'"]')->item(0);
            $this->assertSame((string) $quantity, $row->getAttribute('data-cart-saved-quantity'));
            $addControls = $xpath->query('.//*[@data-cart-add-controls]', $row)->item(0);
            $this->assertSame($explanation === null ? 'true' : 'false', $addControls->getAttribute('data-cart-can-add'));
            $this->assertSame($quantity > 0, str_contains($addControls->getAttribute('class'), 'hidden'));
            $cartControls = $xpath->query('.//*[@data-cart-in-cart-controls]', $row)->item(0);
            $this->assertSame($quantity === 0, str_contains($cartControls->getAttribute('class'), 'hidden'));
            $this->assertSame((string) $quantity, trim($xpath->query('.//*[@data-cart-quantity]', $cartControls)->item(0)->textContent));
            $button = $xpath->query('.//button', $addControls)->item(0);
            $this->assertSame($quantity > 0 || $explanation !== null, $button->hasAttribute('disabled'));
            $descriptionId = $button->getAttribute('aria-describedby');
            $feedback = $xpath->query('.//*[@id="'.$descriptionId.'"]', $row);
            $this->assertSame(1, $feedback->length);
            $this->assertSame($descriptionId, $xpath->query('.//input[@name="quantity"]', $addControls)->item(0)->getAttribute('aria-describedby'));
            $this->assertSame($descriptionId, $xpath->query('.//form[@data-cart-remove-form]//button', $cartControls)->item(0)->getAttribute('aria-describedby'));
            if ($explanation !== null) {
                $availability = $xpath->query('.//*[@data-cart-availability]', $feedback->item(0))->item(0);
                $this->assertSame($explanation, trim($availability->textContent));
                $this->assertFalse($availability->hasAttribute('hidden'));
                $this->assertStringNotContainsString('sr-only', $feedback->item(0)->getAttribute('class'));
            }
        }
    }
}
