<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Models\AuditEvent;
use App\Models\Cart;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PharmacySupplierDiscount;
use App\Models\PriceListImport;
use App\Models\ProductCategory;
use App\Models\Subscription;
use App\Models\SupplierRequest;
use App\Models\User;
use App\Services\CatalogSearchService;
use App\Services\SupplierCartExport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SupplierDiscountPricingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_defaults_and_agreements_select_the_higher_discount_in_catalog_and_cart_for_each_pharmacy(): void
    {
        [$supplier, $import] = $this->activeImport();
        $supplier->update(['default_discount_percent' => '12.34']);
        $offer = $this->offer($supplier, $import, 'Акционный товар', 'акционный товар', '10.00');
        $offer->update(['old_price' => '20.00']);
        $secondOffer = $this->offer($supplier, $import, 'Другой товар', 'другой товар', '20.00');
        foreach ([null, '0.00', '5.00', '12.34', '15.00', '100.00'] as $agreement) {
            [$pharmacy, $user] = $this->pharmacyUser();
            if ($agreement !== null) {
                PharmacySupplierDiscount::create(['pharmacy_organization_id' => $pharmacy->id, 'supplier_organization_id' => $supplier->id, 'supplier_discount_percent' => $agreement]);
            }
            $expectedPercent = match ($agreement) {
                '15.00' => '15.00', '100.00' => '100.00', default => '12.34'
            };
            $expectedPrice = match ($agreement) {
                '15.00' => '8.50', '100.00' => '0.00', default => '8.76'
            };
            $result = app(CatalogSearchService::class)->search([], $pharmacy->id);
            $catalogOffer = collect($result->items())->firstWhere('id', $offer->id);
            $this->assertSame($expectedPrice, $catalogOffer->effective_price);
            $this->assertSame($expectedPercent, $catalogOffer->applied_supplier_discount_percent);
            $this->assertSame('20.00', $catalogOffer->old_price);
            $this->actingAs($user)->post(route('cart.add', $offer))->assertRedirect();
            $this->actingAs($user)->post(route('cart.add', $secondOffer))->assertRedirect();
            $items = Cart::query()->where('user_id', $user->id)->sole()->items()->get();
            $this->assertSame($expectedPrice, $items->firstWhere('offer_id', $offer->id)->unit_price);
            $this->assertSame($expectedPercent, $items->firstWhere('offer_id', $secondOffer->id)->snapshot['supplier_discount_percent']);
            $this->actingAs($user)->get(route('partners.index'))->assertSee('Скидка по умолчанию 12.34%')->assertSee('Применяется '.$expectedPercent.'%');
        }
    }

    public function test_changed_defaults_preserve_cart_and_share_prices(): void
    {
        [$pharmacy, $user] = $this->pharmacyUser();
        [$supplier, $import] = $this->activeImport();
        $supplier->update(['default_discount_percent' => '12.34']);
        $offer = $this->offer($supplier, $import, 'Товар', 'товар', '10.00');
        $this->actingAs($user)->post(route('cart.add', $offer))->assertRedirect();
        $supplier->update(['default_discount_percent' => '50.00']);
        $this->actingAs($user)->post(route('cart.add', $offer))->assertRedirect();
        $item = Cart::query()->where('user_id', $user->id)->sole()->items()->sole();
        $this->assertSame('8.76', $item->unit_price);
        $this->assertSame('12.34', $item->snapshot['supplier_discount_percent']);
        $this->actingAs($user)->patchJson(route('cart.update', $item), ['quantity' => 3])->assertOk()->assertJsonPath('supplier.total', '26.28')->assertJsonPath('item.line_total', '26.28');
        $this->actingAs($user)->get(route('cart'))->assertSee('26.28 TJS');
        $export = app(SupplierCartExport::class)->data($user, $supplier);
        $this->assertSame('26.28', $export['total']);
        $this->assertSame('8.76', $export['items'][0]['price']);
        $this->actingAs($user)->post(route('cart.suppliers.archive', $supplier))->assertRedirect();
        $archive = SupplierRequest::query()->sole();
        $this->assertSame('26.28', $archive->total);
        $this->assertSame('8.76', $archive->items()->sole()->unit_price);
        $this->assertSame('12.34', $archive->items()->sole()->snapshot['supplier_discount_percent']);
    }

    public function test_default_discount_drives_sorting_pagination_filters_and_facets_without_affecting_other_suppliers_or_modes(): void
    {
        config(['catalog.page_size' => 1]);
        [$pharmacy, $user] = $this->pharmacyUser();
        [$supplier, $import] = $this->activeImport();
        [$other, $otherImport] = $this->activeImport();
        $supplier->update(['default_discount_percent' => '50.00']);
        $discounted = $this->offer($supplier, $import, 'Со скидкой', 'со скидкой', '10.00');
        $regular = $this->offer($other, $otherImport, 'Обычный', 'обычный', '6.00');
        $category = ProductCategory::query()->where('is_active', true)->firstOrFail();
        $discounted->medicine->categories()->sync([$category->id => ['source' => 'test', 'confidence' => 100]]);
        $service = app(CatalogSearchService::class);
        $first = $service->search([], $pharmacy->id);
        $this->assertSame($discounted->id, $first->items()[0]->id);
        $second = $service->search(['cursor' => $first->nextCursor()], $pharmacy->id);
        $this->assertSame($regular->id, $second->items()[0]->id);
        $this->assertSame('6.00', $second->items()[0]->effective_price);
        $this->assertSame($regular->id, $service->search(['sort' => 'price_desc'], $pharmacy->id)->items()[0]->id);
        $filters = ['min_price' => '5.00', 'max_price' => '5.00'];
        $this->assertSame(1, $service->total($filters, $pharmacy->id));
        $this->assertSame(1, $service->facets($filters, $pharmacy->id)->sole()['count']);
        $this->assertSame(0, $service->total(['max_price' => '4.99'], $pharmacy->id));
        $this->assertSame($regular->id, $service->search([])->items()[0]->id);
        $this->actingAs($user)->post(route('cart.add', $regular))->assertRedirect();
        $this->assertSame('6.00', Cart::query()->where('user_id', $user->id)->sole()->items()->sole()->unit_price);
    }

    public function test_catalog_truncates_fractional_cents_without_losing_a_cent_on_undiscounted_prices(): void
    {
        [$pharmacy] = $this->pharmacyUser();
        [$supplier, $import] = $this->activeImport();
        $offer = $this->offer($supplier, $import, 'Точная цена', 'точная цена', '0.29');
        $service = app(CatalogSearchService::class);
        $this->assertSame('0.29', $service->search([], $pharmacy->id)->items()[0]->effective_price);
        $supplier->update(['default_discount_percent' => '1.00']);
        $this->assertSame('0.28', $service->search([], $pharmacy->id)->items()[0]->effective_price);
    }

    public function test_pharmacy_catalog_uses_its_discount_for_price_filters_and_keeps_raw_price_context(): void
    {
        [$pharmacy, $user] = $this->pharmacyUser();
        [$supplier, $import] = $this->activeImport();
        PharmacySupplierDiscount::create([
            'pharmacy_organization_id' => $pharmacy->id,
            'supplier_organization_id' => $supplier->id,
            'supplier_discount_percent' => '12.34',
        ]);
        $this->offer($supplier, $import, 'Скидочный товар', 'скидочный товар', '10.00');

        $response = $this->actingAs($user)->getJson(route('catalog.search', ['q' => 'скидочный', 'min_price' => '8.76', 'max_price' => '8.76']));

        $response->assertOk();
        $this->assertStringContainsString('8.76 <span class="text-sm">TJS</span>', $response->json('html'));
        $this->assertStringContainsString('10.00 TJS · Ваша скидка 12.34%', $response->json('html'));
        $this->actingAs($user)->getJson(route('catalog.search', ['q' => 'скидочный', 'min_price' => '8.77']))->assertJsonPath('pagination.returned', 0);

        $this->actingAs($user)->patch(route('partners.discount', $supplier), ['supplier_discount_percent' => ''])->assertSessionHas('success');
        $response = $this->actingAs($user)->getJson(route('catalog.search', ['q' => 'скидочный', 'min_price' => '10.00', 'max_price' => '10.00']));

        $response->assertOk();
        $this->assertStringContainsString('10.00 <span class="text-sm">TJS</span>', $response->json('html'));
        $this->assertStringNotContainsString('Ваша скидка 12.34%', $response->json('html'));
    }

    public function test_cart_and_order_snapshot_the_effective_price_without_repricing_existing_lines(): void
    {
        config()->set('orders.placement_enabled', true);
        [$pharmacy, $user] = $this->pharmacyUser();
        [$supplier, $import] = $this->activeImport();
        $discount = PharmacySupplierDiscount::create([
            'pharmacy_organization_id' => $pharmacy->id,
            'supplier_organization_id' => $supplier->id,
            'supplier_discount_percent' => '12.34',
        ]);
        $offer = $this->offer($supplier, $import, 'Товар в корзине', 'товар в корзине', '10.00');

        $this->actingAs($user)->post(route('cart.add', $offer), ['quantity' => 1])->assertRedirect();
        $cartItem = Cart::query()->where('user_id', $user->id)->firstOrFail()->items()->firstOrFail();
        $this->assertSame('8.76', $cartItem->unit_price);
        $this->assertSame('10.00', $cartItem->snapshot['raw_price']);
        $this->assertSame('12.34', $cartItem->snapshot['supplier_discount_percent']);

        $discount->update(['supplier_discount_percent' => '50.00']);
        $supplier->update(['default_discount_percent' => '75.00']);
        $this->actingAs($user)->post(route('cart.add', $offer), ['quantity' => 1])->assertRedirect();
        $this->assertSame('8.76', $cartItem->fresh()->unit_price);

        $this->actingAs($user)->post(route('cart.checkout'))->assertRedirect(route('orders.index'));
        $order = Order::query()->sole();
        $this->assertSame('17.52', $order->total);
        $this->assertSame('8.76', $order->items()->sole()->unit_price);
    }

    public function test_partner_discount_can_be_created_updated_and_cleared_with_audit_payload(): void
    {
        [$pharmacy, $user] = $this->pharmacyUser();
        $supplier = Organization::factory()->wholesaler()->create();

        $this->actingAs($user)->patch(route('partners.discount', $supplier), ['supplier_discount_percent' => '100.00'])->assertSessionHas('success');
        $this->actingAs($user)->patch(route('partners.discount', $supplier), ['supplier_discount_percent' => '0.00'])->assertSessionHas('success');
        $this->actingAs($user)->patch(route('partners.discount', $supplier), ['supplier_discount_percent' => ''])->assertSessionHas('success');

        $this->assertDatabaseMissing('pharmacy_supplier_discounts', ['pharmacy_organization_id' => $pharmacy->id, 'supplier_organization_id' => $supplier->id]);
        $events = AuditEvent::query()->whereIn('event', ['pharmacy_supplier_discount.created', 'pharmacy_supplier_discount.updated', 'pharmacy_supplier_discount.cleared'])->orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertSame('100.00', $events->first()->after['supplier_discount_percent']);
        $this->assertSame('0.00', $events->get(1)->after['supplier_discount_percent']);
        $this->assertSame('0.00', $events->last()->before['supplier_discount_percent']);
    }

    /** @return array{Organization, User} */
    private function pharmacyUser(): array
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();

        return [$pharmacy, $user];
    }

    /** @return array{Organization, PriceListImport} */
    private function activeImport(): array
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $supplier->update(['active_price_list_import_id' => $import->id]);

        return [$supplier, $import];
    }

    private function offer(Organization $supplier, PriceListImport $import, string $name, string $searchText, string $price): Offer
    {
        $medicine = Medicine::factory()->create(['supplier_organization_id' => $supplier->id, 'name' => $name, 'normalized_name' => $name, 'search_text' => $searchText]);

        return Offer::factory()->for($supplier, 'organization')->for($medicine)->create(['price_list_import_id' => $import->id, 'price' => $price, 'quantity' => 10]);
    }
}
