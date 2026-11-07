<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Enums\PromotionStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PharmacySupplierDiscount;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\Promotion;
use App\Models\PromotionItem;
use App\Models\Subscription;
use App\Models\SupplierProduct;
use App\Models\SupplierRequest;
use App\Models\User;
use App\Services\PriceList\ImportActivator;
use App\Services\PromotionWorkflow;
use App\Services\SupplierCartExport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PromotionCartTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function basket(): array
    {
        $this->freezeTime();
        config(['orders.placement_enabled' => true]);
        $buyer = User::factory()->pharmacy()->create();
        Subscription::factory()->for($buyer)->create();
        $promotion = Promotion::factory()->published()->create();
        $item = PromotionItem::factory()->for($promotion)->create(['name' => 'Акционный товар', 'price' => '100.00']);

        return [$buyer, $promotion, $item];
    }

    private function add(Promotion $promotion, PromotionItem $item, int $quantity = 1): TestResponse
    {
        return $this->post(route('cart.promotions.add', $promotion), ['items' => [['id' => $item->id, 'quantity' => $quantity]], 'open_cart' => 1]);
    }

    private function ordinaryOffer(Organization $supplier): Offer
    {
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $supplier->update(['active_price_list_import_id' => $import->id]);

        return Offer::factory()->for($supplier)->create(['price_list_import_id' => $import->id, 'price' => '50.00', 'quantity' => 100]);
    }

    private function edit(Promotion $promotion, PromotionItem $item, array $changes): void
    {
        $admin = User::factory()->admin()->create();
        app(PromotionWorkflow::class)->locked($promotion, function (Promotion $current) use ($admin, $item, $changes): void {
            $item = $current->items()->findOrFail($item->id);
            $item->fill($changes);
            $item->terms_version++;
            $item->save();
            app(PromotionWorkflow::class)->edited($current, $admin);
        });
    }

    public function test_mixed_cart_keeps_regular_and_promotion_lines_separate_and_merges_repeat_additions(): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        $offer = $this->ordinaryOffer($promotion->organization);
        $item->update(['source_offer_id' => $offer->id]);
        $this->actingAs($buyer)->post(route('cart.add', $offer), ['quantity' => 2])->assertRedirect();
        $this->add($promotion, $item, 3)->assertRedirect(route('cart'));
        $this->add($promotion, $item, 2)->assertRedirect(route('cart'));

        $this->assertDatabaseCount('cart_items', 2);
        $row = CartItem::where('promotion_item_id', $item->id)->sole();
        $this->assertSame(5, $row->quantity);
        $this->assertNull($row->offer_id);
        $this->assertSame('100.00', $row->unit_price);
        $this->get(route('cart'))->assertSee('Акционный товар')->assertSee('600.00 TJS');
        $this->patchJson(route('cart.update', $row), ['quantity' => 4])->assertJsonPath('cart.total', '500.00')->assertJsonPath('supplier.total', '500.00');
        $this->postJson(route('cart.share'))->assertJsonPath('excluded', 0)->assertJsonPath('share_text', "Заявки OAPTEKA\n".$promotion->organization->name."\n".$offer->medicine->name." — 2 × 50.00 TJS\nАкционный товар — 4 × 100.00 TJS\nИтого: 500.00 TJS\nОбщий итог: 500.00 TJS");
        $this->delete(route('cart.items.destroy', $row))->assertRedirect();
        $this->assertDatabaseCount('cart_items', 1);
    }

    #[TestWith(['final', null, '100.00'])]
    #[TestWith(['pharmacy_discount', null, '90.00'])]
    #[TestWith(['pharmacy_discount', '0.00', '100.00'])]
    #[TestWith(['pharmacy_discount', '20.00', '80.00'])]
    public function test_price_modes_respect_agreement_overrides_and_supplier_discount(string $mode, ?string $agreement, string $price): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        $promotion->organization->update(['default_discount_percent' => '10.00']);
        $item->update(['price_mode' => $mode]);
        if ($agreement !== null) {
            PharmacySupplierDiscount::create(['pharmacy_organization_id' => $buyer->organization_id, 'supplier_organization_id' => $promotion->organization_id, 'supplier_discount_percent' => $agreement]);
        }
        $this->actingAs($buyer);
        $this->add($promotion, $item)->assertRedirect();

        $this->assertSame($price, CartItem::query()->sole()->unit_price);
        $promotion->organization->update(['default_discount_percent' => '40.00']);
        $this->get(route('cart'))->assertOk();
        $this->assertSame($price, CartItem::query()->sole()->unit_price);
        $this->assertNull(CartItem::query()->sole()->offer_change);
    }

    public function test_agreement_changes_require_acceptance_for_discounted_promotions_but_preserve_final_prices(): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        $item->update(['price_mode' => 'pharmacy_discount']);
        $finalItem = PromotionItem::factory()->for($promotion)->create(['name' => 'Окончательный товар']);
        $this->actingAs($buyer);
        $this->add($promotion, $item)->assertRedirect();
        $this->add($promotion, $finalItem)->assertRedirect();
        $this->patch(route('partners.discount', $promotion->organization), ['supplier_discount_percent' => '15.00'])->assertRedirect();

        $row = CartItem::where('promotion_item_id', $item->id)->sole();
        $this->assertSame('100.00', $row->unit_price);
        $this->assertSame('85.00', $row->proposed_unit_price);
        $this->assertNull(CartItem::where('promotion_item_id', $finalItem->id)->sole()->offer_change);
        $this->post(route('cart.items.accept-promotion-terms', $row), ['terms_version' => $row->offer_change['promotion_terms_version']])->assertRedirect(route('cart'));
        $this->assertSame('85.00', $row->fresh()->unit_price);
        $this->patch(route('partners.discount', $promotion->organization), ['supplier_discount_percent' => ''])->assertRedirect();
        $this->assertSame('100.00', $row->fresh()->proposed_unit_price);
        $this->get(route('cart'))->assertSee('Подтвердить актуальные условия');
        $this->assertSame('100.00', $row->fresh()->proposed_unit_price);
        $this->patchJson(route('cart.update', $row), ['quantity' => 2])->assertJsonPath('cart.excluded_count', 1);
        $this->assertSame('100.00', $row->fresh()->proposed_unit_price);
        $this->post(route('cart.items.accept-promotion-terms', $row), ['terms_version' => $row->fresh()->offer_change['promotion_terms_version']])->assertRedirect();
        $this->assertSame('100.00', $row->fresh()->unit_price);
        $this->assertSame('0.00', $row->fresh()->snapshot['supplier_discount_percent']);
    }

    #[TestWith(['price', '90.00'])]
    #[TestWith(['price', '110.00'])]
    #[TestWith(['ends_on', '2027-12-31'])]
    public function test_changed_price_or_period_excludes_line_until_current_version_is_confirmed(string $attribute, string $value): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        $this->actingAs($buyer);
        $this->add($promotion, $item, 2)->assertRedirect();
        $row = CartItem::query()->sole();
        if ($attribute === 'price') {
            $this->edit($promotion, $item, [$attribute => $value]);
        } else {
            $admin = User::factory()->admin()->create();
            app(PromotionWorkflow::class)->save($admin, $promotion, array_replace($promotion->only(['organization_id', 'title', 'description', 'conditions', 'city']), ['starts_on' => $promotion->starts_on->toDateString(), 'ends_on' => $value]));
        }
        $this->get(route('cart'))->assertSee('Подтвердить актуальные условия')->assertSee('Условия акции изменились');
        $this->postJson(route('cart.checkout'))->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->postJson(route('cart.share'))->assertUnprocessable();
        $this->add($promotion, $item)->assertSessionHasErrors('cart');
        $row->refresh();
        $this->assertSame('100.00', $row->unit_price);
        $this->assertSame(2, $row->quantity);
        $this->post(route('cart.items.accept-promotion-terms', $row), ['terms_version' => $row->offer_change['promotion_terms_version']])->assertRedirect();
        $this->post(route('cart.checkout'))->assertRedirect(route('orders.index'));

        $ordered = Order::query()->sole()->items()->sole();
        $this->assertSame($item->id, $ordered->promotion_item_id);
        $this->assertSame($promotion->id, $ordered->promotion_id);
        $this->assertSame($attribute === 'price' ? $value : '100.00', $ordered->unit_price);
        $this->assertSame($attribute === 'ends_on' ? $value : $promotion->ends_on->toDateString(), $ordered->snapshot['ends_on']);
        $snapshot = $ordered->snapshot;
        $this->edit($promotion->fresh(), $item->fresh(), ['price' => '250.00']);
        $this->assertSame($snapshot, $ordered->fresh()->snapshot);
    }

    public function test_stale_terms_confirmation_cannot_accept_a_second_change(): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        $this->actingAs($buyer);
        $this->add($promotion, $item)->assertRedirect();
        $this->edit($promotion, $item, ['price' => '110.00']);
        $this->get(route('cart'))->assertOk();
        $row = CartItem::query()->sole();
        $stale = $row->offer_change['promotion_terms_version'];
        $this->edit($promotion->fresh(), $item->fresh(), ['price' => '120.00']);
        $this->postJson(route('cart.items.accept-promotion-terms', $row), ['terms_version' => $stale])->assertUnprocessable();
        $this->assertSame('100.00', $row->fresh()->unit_price);
        $this->assertDatabaseMissing('audit_events', ['event' => 'cart.promotion_terms_accepted']);
        $this->get(route('cart'))->assertOk();
        $row->refresh();
        $this->post(route('cart.items.accept-promotion-terms', $row), ['terms_version' => $row->offer_change['promotion_terms_version']])->assertRedirect();
        $this->assertSame('120.00', $row->fresh()->unit_price);
    }

    #[TestWith(['expired'])]
    #[TestWith(['draft'])]
    #[TestWith(['unavailable'])]
    #[TestWith(['deleted'])]
    #[TestWith(['quote'])]
    #[TestWith(['minimum'])]
    #[TestWith(['stock'])]
    public function test_unavailable_promotion_lines_remain_in_cart_and_are_excluded_from_all_outputs(string $change): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        $offer = $this->ordinaryOffer($promotion->organization);
        $this->actingAs($buyer)->post(route('cart.add', $offer))->assertRedirect();
        $this->add($promotion, $item, 2)->assertRedirect();
        $row = CartItem::where('promotion_item_id', $item->id)->sole();
        match ($change) {
            'expired' => $promotion->update(['ends_on' => now('Asia/Dushanbe')->subDay()->toDateString()]),
            'draft' => $promotion->update(['status' => PromotionStatus::Draft]),
            'unavailable' => $item->update(['in_stock' => false]),
            'deleted' => $item->delete(),
            'quote' => $item->update(['price' => null]),
            'minimum' => $item->update(['minimum_quantity' => 3]),
            'stock' => $item->update(['stock' => 1]),
        };
        $this->get(route('cart'))->assertSee('Акционный товар')->assertSee('50.00 TJS');
        $this->postJson(route('cart.suppliers.share', $promotion->organization), ['shared_via' => 'clipboard'])->assertJsonPath('excluded', 1);
        $data = app(SupplierCartExport::class)->data($buyer, $promotion->organization->fresh());
        $this->assertSame(1, $data['excluded']);
        $this->assertSame('50.00', $data['total']);
        $this->assertCount(1, $data['items']);
        $this->post(route('cart.suppliers.archive', $promotion->organization))->assertRedirect();
        $this->assertSame(2, $row->fresh()->quantity);
        $this->assertSame('50.00', SupplierRequest::query()->sole()->total);
        $this->assertSame(1, SupplierRequest::query()->sole()->items()->count());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_mixed_supplier_checkout_saves_identifiers_prices_and_frozen_terms(): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        $offer = $this->ordinaryOffer($promotion->organization);
        $this->actingAs($buyer)->post(route('cart.add', $offer), ['quantity' => 2])->assertRedirect();
        $this->add($promotion, $item, 3)->assertRedirect();
        $this->post(route('cart.suppliers.checkout', $promotion->organization))->assertRedirect(route('orders.index'));

        $order = Order::query()->sole();
        $this->assertSame('400.00', $order->total);
        $this->assertSame(2, $order->items()->count());
        $promotional = $order->items()->where('promotion_item_id', $item->id)->sole();
        $this->assertNull($promotional->offer_id);
        $this->assertSame($promotion->id, $promotional->promotion_id);
        $this->assertSame('100.00', $promotional->unit_price);
        $this->assertSame('final', $promotional->snapshot['price_mode']);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_archived_promotion_snapshot_and_exports_survive_later_edits(): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        $this->actingAs($buyer);
        $this->add($promotion, $item, 2)->assertRedirect();
        $this->get(route('cart.suppliers.pdf', $promotion->organization))->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('cart.suppliers.excel', $promotion->organization))->assertDownload();
        $this->post(route('cart.suppliers.archive', $promotion->organization))->assertRedirect();
        $archive = SupplierRequest::query()->sole();
        $line = $archive->items()->sole();
        $this->edit($promotion, $item, ['price' => '900.00', 'name' => 'Другое название']);
        $item->delete();
        $this->assertSame($promotion->id, $line->promotion_id);
        $this->assertSame($item->id, $line->promotion_item_id);
        $this->assertSame('100.00', $line->fresh()->unit_price);
        $this->get(route('supplier-requests.show', $archive))->assertSee('Акционный товар')->assertDontSee('Другое название');
        $this->get(route('supplier-requests.excel', $archive))->assertDownload();
    }

    public function test_cart_source_constraint_rejects_rows_with_both_sources_or_neither(): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        $offer = $this->ordinaryOffer($promotion->organization);
        $cart = Cart::create(['user_id' => $buyer->id]);
        foreach ([[null, null], [$offer->id, $item->id]] as [$offerId, $promotionItemId]) {
            try {
                DB::table('cart_items')->insert(['cart_id' => $cart->id, 'offer_id' => $offerId, 'promotion_item_id' => $promotionItemId, 'quantity' => 1, 'unit_price' => 1, 'snapshot' => '{}']);
                $this->fail('Invalid source accepted');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Cart item requires exactly one source', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_unknown_price_minimum_stock_and_cross_promotion_validation_are_atomic(): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        $quote = PromotionItem::factory()->quoteOnly()->for($promotion)->create();
        $minimum = PromotionItem::factory()->for($promotion)->create(['minimum_quantity' => 3, 'stock' => 5]);
        $foreign = PromotionItem::factory()->create();
        $this->actingAs($buyer);
        $this->add($promotion, $quote)->assertSessionHasErrors('quantity');
        $this->add($promotion, $minimum, 2)->assertSessionHasErrors('quantity');
        $this->add($promotion, $minimum, 6)->assertSessionHasErrors('quantity');
        $this->add($promotion, $foreign)->assertNotFound();
        $this->post(route('cart.promotions.add', $promotion), ['items' => [['id' => $item->id, 'quantity' => 1], ['id' => $quote->id, 'quantity' => 1]]])->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('cart_items', 0);
        $this->add($promotion, $minimum, 3)->assertRedirect();
        $this->add($promotion, $minimum, 2)->assertRedirect();
        $this->assertSame(5, CartItem::query()->sole()->quantity);
    }

    public function test_placement_flag_keeps_order_action_as_cart_navigation_without_checkout(): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        config(['orders.placement_enabled' => false]);
        $this->actingAs($buyer);
        $this->add($promotion, $item)->assertRedirect(route('cart'));
        $this->post(route('cart.checkout'))->assertRedirect(route('cart'))->assertSessionHas('warning');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_import_activation_reconciles_ordinary_cart_and_preserves_promotion_copy(): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        $offer = $this->ordinaryOffer($promotion->organization);
        $product = SupplierProduct::factory()->for($promotion->organization, 'supplier')->create(['medicine_id' => $offer->medicine_id]);
        $offer->update(['supplier_product_id' => $product->id]);
        $item->update(['source_offer_id' => $offer->id, 'supplier_product_id' => $product->id]);
        $this->actingAs($buyer)->post(route('cart.add', $offer), ['quantity' => 4])->assertRedirect();
        $this->add($promotion, $item, 2)->assertRedirect();
        $next = PriceListImport::factory()->for($promotion->organization, 'supplier')->create(['status' => PriceListImportStatus::Preview, 'inventory_at' => null]);
        PriceListImportRow::factory()->for($next, 'import')->create(['source_row' => 1, 'supplier_product_id' => $product->id, 'medicine_id' => $product->medicine_id,
            'parsed_values' => ['name' => $product->original_name, 'normalized_name' => $product->normalized_name, 'price' => '60.00', 'quantity' => 100, 'total' => null, 'expiration' => null],
            'disposition' => PriceListRowDisposition::Valid, 'planned_action' => PriceListRowAction::Update]);

        app(ImportActivator::class)->activate($next);

        $this->assertDatabaseCount('cart_items', 2);
        $ordinary = CartItem::whereNotNull('offer_id')->sole();
        $this->assertSame(4, $ordinary->quantity);
        $this->assertSame($next->offers()->sole()->id, $ordinary->offer_id);
        $this->assertSame('60.00', $ordinary->proposed_unit_price);
        $promotional = CartItem::whereNotNull('promotion_item_id')->sole();
        $this->assertSame(2, $promotional->quantity);
        $this->assertSame('100.00', $promotional->unit_price);
        $this->assertNull($promotional->offer_change);
        $this->assertSame('100.00', $item->fresh()->price);
    }

    public function test_switching_to_pharmacy_discount_requires_acceptance_of_current_supplier_default(): void
    {
        [$buyer, $promotion, $item] = $this->basket();
        $promotion->organization->update(['default_discount_percent' => '10.00']);
        $this->actingAs($buyer);
        $this->add($promotion, $item)->assertRedirect();
        $this->edit($promotion, $item, ['price_mode' => 'pharmacy_discount']);
        $this->get(route('cart'))->assertSee('Подтвердить актуальные условия');
        $row = CartItem::query()->sole();
        $this->assertSame('90.00', $row->proposed_unit_price);
        $this->post(route('cart.items.accept-promotion-terms', $row), ['terms_version' => $row->offer_change['promotion_terms_version']])->assertRedirect();
        $this->get(route('cart'))->assertOk();
        $this->assertSame('90.00', $row->fresh()->unit_price);
        $this->assertNull($row->fresh()->offer_change);
    }
}
