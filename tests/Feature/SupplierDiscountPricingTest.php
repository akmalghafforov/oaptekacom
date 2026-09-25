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
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SupplierDiscountPricingTest extends TestCase
{
    use LazilyRefreshDatabase;

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
