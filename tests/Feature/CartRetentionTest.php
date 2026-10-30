<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Models\AuditEvent;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\Subscription;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\CartMaintenance;
use App\Services\PriceList\ImportActivator;
use App\Services\SupplierCartExport;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class CartRetentionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_activation_remaps_and_reprices_without_losing_quantity_or_original_snapshot(): void
    {
        [$user, $supplier, $offer, $item] = $this->basket();
        $snapshot = $item->snapshot;
        $import = $this->replacement($supplier, $offer, '20.00', 3);

        app(ImportActivator::class)->activate($import);

        $item->refresh();
        $this->assertSame($import->offers()->sole()->id, $item->offer_id);
        $this->assertSame(7, $item->quantity);
        $this->assertSame('9.00', $item->unit_price);
        $this->assertSame('18.00', $item->proposed_unit_price);
        $this->assertSame($snapshot, array_diff_key($item->original_snapshot, array_flip(['unit_price', 'offer_id'])));
        $this->assertSame('9.00', $item->original_snapshot['unit_price']);
        $this->assertSame('20.00', $item->snapshot['raw_price']);
        $this->assertSame('insufficient_stock', $item->availability_reason);
        $this->assertDatabaseHas('audit_events', ['event' => 'cart.item_remapped', 'subject_id' => $item->id]);
        $this->assertDatabaseHas('audit_events', ['event' => 'cart.item_unavailable', 'subject_id' => $item->id]);
        $this->actingAs($user)->get(route('cart'))->assertDontSeeText('Предложение заменено')->assertSeeText('Цена повысилась')->assertSeeText('Недостаточный остаток');
        $this->assertSame(7, $item->fresh()->quantity);
        $this->patchJson(route('cart.update', $item), ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('availability.0.warning', null)
            ->assertJsonPath('cart.excluded_count', 1)
            ->assertJsonPath('cart.total', '0.00');
        $this->assertNull($item->fresh()->availability_reason);
        $this->assertSame('18.00', $item->fresh()->proposed_unit_price);
    }

    public function test_missing_replacement_survives_repeated_page_views_and_later_recovers(): void
    {
        [$user, $supplier, $offer, $item] = $this->basket();
        $missing = $this->replacement($supplier, $offer, matches: false);
        app(ImportActivator::class)->activate($missing);

        $this->actingAs($user)->get(route('cart'))->assertSeeText('Товар отсутствует в новом прайс-листе');
        $this->get(route('cart'))->assertSeeText('Товар отсутствует в новом прайс-листе');

        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'offer_id' => $offer->id, 'quantity' => 7, 'availability_reason' => 'missing_replacement']);
        $this->assertSame(1, AuditEvent::where('event', 'cart.item_unavailable')->count());
        $restored = $this->replacement($supplier, $offer);
        app(ImportActivator::class)->activate($restored);
        $this->assertSame($restored->offers()->sole()->id, $item->fresh()->offer_id);
        $this->assertNull($item->fresh()->availability_reason);
    }

    public function test_out_of_stock_replacement_is_retained_and_checkout_is_rejected(): void
    {
        config()->set('orders.placement_enabled', true);
        [$user, $supplier, $offer, $item] = $this->basket();
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer, quantity: 0));

        $this->actingAs($user)->get(route('cart'))->assertSeeText('Товара нет в наличии');
        $this->postJson(route('cart.checkout'))->assertUnprocessable();

        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 7, 'availability_reason' => 'out_of_stock']);
        $this->assertDatabaseCount('checkouts', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseMissing('audit_events', ['event' => 'cart.checkout_removed']);
    }

    #[TestWith(['item', 'cart.item_deleted'])]
    #[TestWith(['supplier', 'cart.supplier_cleared'])]
    #[TestWith(['all', 'cart.cleared'])]
    public function test_explicit_removal_audits_the_owner_quantity_and_snapshot(string $scope, string $event): void
    {
        [$user, $supplier, , $item] = $this->basket();
        $url = match ($scope) {
            'item' => route('cart.items.destroy', $item),
            'supplier' => route('cart.suppliers.destroy', $supplier),
            'all' => route('cart.destroy'),
        };

        $this->actingAs($user)->delete($url)->assertRedirect();

        $this->assertModelMissing($item);
        $audit = AuditEvent::where('event', $event)->sole();
        $this->assertSame($user->id, $audit->user_id);
        $this->assertSame($user->id, $audit->before['user_id']);
        $this->assertSame(7, $audit->before['items'][0]['quantity']);
        $this->assertSame($item->snapshot, $audit->before['items'][0]['snapshot']);
    }

    #[TestWith(['archive', 'cart.archived', 'supplier_requests'])]
    #[TestWith(['checkout', 'cart.checkout_removed', 'checkouts'])]
    public function test_remapped_items_are_removed_only_with_a_persisted_archive_or_checkout(string $action, string $event, string $table): void
    {
        config()->set('orders.placement_enabled', true);
        [$user, $supplier, $offer, $item] = $this->basket();
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer, '20.00'));

        $this->actingAs($user)->post(route('cart.items.accept-price', $item), ['offer_id' => $item->fresh()->offer_id, 'proposed_unit_price' => '18.00'])->assertRedirect();

        $this->actingAs($user)->post(route('cart.suppliers.'.$action, $supplier))->assertRedirect();

        $this->assertModelMissing($item);
        $this->assertDatabaseCount($table, 1);
        $this->assertDatabaseHas($action === 'archive' ? 'supplier_request_items' : 'order_items', ['quantity' => 7, 'unit_price' => '18.00']);
        $audit = AuditEvent::where('event', $event)->sole();
        $this->assertSame(1, $audit->after[$action === 'archive' ? 'supplier_request_id' : 'checkout_id']);
        $this->assertSame('9.00', $audit->before['items'][0]['original_snapshot']['unit_price']);
    }

    public function test_stale_add_is_rejected_and_existing_item_updates_after_activation(): void
    {
        [$user, $supplier, $offer, $item] = $this->basket();
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer));

        $this->actingAs($user)->postJson(route('cart.add', $offer))->assertForbidden();
        $this->patchJson(route('cart.update', $item), ['quantity' => 8])->assertOk();

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame(8, $item->fresh()->quantity);
    }

    public function test_delayed_cleanup_and_retried_activation_do_not_remove_or_duplicate_items(): void
    {
        [, $supplier, $offer, $item] = $this->basket();
        $first = $this->replacement($supplier, $offer);
        $activator = app(ImportActivator::class);
        $activator->activate($first);
        $second = $this->replacement($supplier, $offer);
        $activator->activate($second);

        $activator->cleanup($first->id, $offer->price_list_import_id);
        $activator->activate($second->fresh());

        $this->assertFalse($first->offers()->sole()->is_active);
        $this->assertSame($second->offers()->sole()->id, $item->fresh()->offer_id);
        $this->assertSame(7, $item->fresh()->quantity);
        $this->assertSame(2, AuditEvent::where('event', 'cart.item_remapped')->count());
    }

    public function test_audit_failure_rolls_back_activation_and_remapping_together(): void
    {
        [, $supplier, $offer, $item] = $this->basket();
        $import = $this->replacement($supplier, $offer);
        $activator = app(ImportActivator::class);
        $row = $import->rows()->sole();
        $activator->materialize($import, $row->id, $row->id);
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->event === 'cart.item_remapped') {
                throw new RuntimeException('Audit storage failure');
            }
        });

        try {
            $activator->commit($import->fresh());
            $this->fail('Activation should roll back when its audit cannot be persisted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit storage failure', $exception->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }

        $this->assertSame($offer->price_list_import_id, $supplier->fresh()->active_price_list_import_id);
        $this->assertSame($offer->id, $item->fresh()->offer_id);
        $this->assertDatabaseMissing('audit_events', ['event' => 'cart.item_remapped']);
    }

    public function test_failed_customer_transaction_rolls_back_removal_and_audit(): void
    {
        [$user, , , $item] = $this->basket();
        $maintenance = app(CartMaintenance::class);

        try {
            $maintenance->transaction($user, [], function () use ($maintenance, $item): void {
                $maintenance->remove($item->cart, collect([$item]), 'cart.item_deleted');
                throw new RuntimeException('Interrupted action');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Interrupted action', $exception->getMessage());
        }

        $this->assertModelExists($item);
        $this->assertDatabaseMissing('audit_events', ['event' => 'cart.item_deleted']);
    }

    public function test_update_reloads_route_binding_if_activation_happens_during_the_request(): void
    {
        [$user, $supplier, $offer, $item] = $this->basket();
        $import = $this->replacement($supplier, $offer, quantity: 5);
        Route::bind('item', function (string $id) use ($import): CartItem {
            $bound = CartItem::with('offer')->findOrFail($id);
            app(ImportActivator::class)->activate($import);

            return $bound;
        });

        $this->actingAs($user)->patchJson(route('cart.update', $item), ['quantity' => 8])->assertUnprocessable();

        $this->assertSame(7, $item->fresh()->quantity);
        $this->assertSame($import->offers()->sole()->id, $item->fresh()->offer_id);
        $this->assertSame('insufficient_stock', $item->fresh()->availability_reason);
    }

    public function test_ambiguous_replacements_preserve_the_line_instead_of_guessing(): void
    {
        [, $supplier, $offer, $item] = $this->basket();
        $offer->update(['batch' => null, 'expires_at' => null]);
        $import = $this->replacement($supplier, $offer);
        $row = $import->rows()->sole()->replicate();
        $row->source_row = 2;
        $row->save();

        app(ImportActivator::class)->activate($import);

        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'offer_id' => $offer->id, 'quantity' => 7, 'availability_reason' => 'ambiguous_replacement']);
        $this->assertDatabaseMissing('audit_events', ['event' => 'cart.item_remapped']);
    }

    public function test_two_old_lines_mapping_to_one_offer_keep_both_requested_quantities(): void
    {
        [, $supplier, $offer, $item] = $this->basket();
        $otherOffer = $offer->replicate();
        $otherOffer->source_row = 2;
        $otherOffer->save();
        $otherItem = $item->replicate();
        $otherItem->offer_id = $otherOffer->id;
        $otherItem->quantity = 4;
        $otherItem->save();
        $import = $this->replacement($supplier, $offer);

        app(ImportActivator::class)->activate($import);

        $this->assertDatabaseCount('cart_items', 2);
        $this->assertSame(11, (int) $item->cart->items()->sum('quantity'));
        $this->assertSame($import->offers()->sole()->id, $item->fresh()->offer_id);
        $this->assertSame('replacement_already_in_cart', $otherItem->fresh()->availability_reason);
    }

    #[TestWith(['10.00', '9.00', null, false])]
    #[TestWith(['8.00', '7.20', null, true])]
    #[TestWith(['15.00', '9.00', '13.50', false])]
    #[TestWith(['25.00', '9.00', '22.50', false])]
    public function test_pending_imports_compare_with_the_accepted_discounted_baseline(string $price, string $accepted, ?string $proposed, bool $decreased): void
    {
        [$user, $supplier, $offer, $item] = $this->basket();
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer, '20.00'));
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer, $price));

        $this->assertSame($accepted, $item->fresh()->unit_price);
        $this->assertSame($proposed, $item->fresh()->proposed_unit_price);
        $this->assertSame(7, $item->fresh()->quantity);
        $response = $this->actingAs($user)->get(route('cart'));
        if ($decreased) {
            $response->assertSeeText('Цена снижена: 9.00 → 7.20 TJS');
        } else {
            $response->assertDontSeeText('Цена снижена');
        }
        $response->assertDontSeeText('Предложение заменено');
    }

    public function test_acceptance_is_explicit_idempotent_and_preserves_stock_and_snapshot(): void
    {
        [$user, $supplier, $offer, $item] = $this->basket();
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer, '20.00', 3));
        $original = $item->fresh()->original_snapshot;
        $payload = ['offer_id' => $item->fresh()->offer_id, 'proposed_unit_price' => '18.00'];
        $this->actingAs($user)->get(route('cart'))
            ->assertSee('data-cart-save-before-submit method="post" action="'.route('cart.items.accept-price', $item).'"', false);
        $this->actingAs($user)->postJson(route('cart.add', $item->fresh()->offer))->assertUnprocessable();
        $this->patchJson(route('cart.update', $item), ['quantity' => 3])->assertOk();
        $this->assertSame('9.00', $item->fresh()->unit_price);
        $this->post(route('cart.items.accept-price', $item), $payload)->assertRedirect(route('cart'));
        $this->post(route('cart.items.accept-price', $item), $payload)->assertRedirect(route('cart'));

        $this->assertSame('18.00', $item->fresh()->unit_price);
        $this->assertNull($item->fresh()->proposed_unit_price);
        $this->assertSame($original, $item->fresh()->original_snapshot);
        $this->assertSame(3, $item->fresh()->quantity);
        $this->assertSame(1, AuditEvent::where('event', 'cart.price_accepted')->count());
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_acceptance_rejects_stale_price_offer_and_other_owners(): void
    {
        [$user, $supplier, $offer, $item] = $this->basket();
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer, '20.00'));
        $payload = ['offer_id' => $item->fresh()->offer_id, 'proposed_unit_price' => '18.00'];
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer, '30.00'));
        $this->actingAs($user)->postJson(route('cart.items.accept-price', $item), $payload)->assertUnprocessable();
        $payload['offer_id'] = $item->fresh()->offer_id;
        $this->postJson(route('cart.items.accept-price', $item), $payload)->assertUnprocessable();
        $other = User::factory()->pharmacy(Organization::factory()->pharmacy()->create())->create();
        Subscription::factory()->for($other)->create();
        $this->actingAs($other)->postJson(route('cart.items.accept-price', $item), ['offer_id' => $item->fresh()->offer_id, 'proposed_unit_price' => '27.00'])->assertForbidden();

        $this->assertSame('9.00', $item->fresh()->unit_price);
        $this->assertSame('27.00', $item->fresh()->proposed_unit_price);
        $this->assertDatabaseMissing('audit_events', ['event' => 'cart.price_accepted']);
    }

    #[TestWith(['checkout'])]
    #[TestWith(['checkout-all'])]
    #[TestWith(['archive'])]
    #[TestWith(['share'])]
    #[TestWith(['export'])]
    public function test_mixed_baskets_only_send_eligible_lines_and_retain_pending_quantities(string $action): void
    {
        config()->set('orders.placement_enabled', true);
        [$user, $supplier, $offer, $item] = $this->basket();
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer, '20.00'));
        $current = $item->fresh()->offer;
        $eligibleOffer = $current->replicate();
        $eligibleOffer->supplier_product_id = null;
        $eligibleOffer->source_row = 2;
        $eligibleOffer->save();
        $eligible = $item->replicate();
        $eligible->offer_id = $eligibleOffer->id;
        $eligible->snapshot = array_replace($item->snapshot, ['medicine' => 'Eligible product']);
        $eligible->quantity = 2;
        $eligible->save();
        $this->actingAs($user);

        if ($action === 'export') {
            $data = app(SupplierCartExport::class)->data($user, $supplier);
            $this->assertSame(1, count($data['items']));
            $this->assertSame('18.00', $data['total']);
            $this->assertSame(1, $data['excluded']);
            $this->get(route('cart.suppliers.pdf', $supplier))->assertOk();
            $this->get(route('cart.suppliers.excel', $supplier))->assertOk();
        } elseif ($action === 'share') {
            $this->postJson(route('cart.suppliers.share', $supplier), ['shared_via' => 'clipboard'])->assertJsonPath('share_text', "Заявки OAPTEKA\n{$supplier->name}\nEligible product — 2 × 9.00 TJS\nИтого: 18.00 TJS\nОбщий итог: 18.00 TJS");
            $this->postJson(route('cart.share'))->assertJsonPath('excluded', 1);
        } else {
            $this->post($action === 'checkout-all' ? route('cart.checkout') : route('cart.suppliers.'.$action, $supplier))->assertRedirect();
            $this->assertModelMissing($eligible);
            $this->assertDatabaseHas(str_starts_with($action, 'checkout') ? 'order_items' : 'supplier_request_items', ['offer_id' => $eligibleOffer->id, 'quantity' => 2]);
        }
        $this->assertSame(7, $item->fresh()->quantity);
        $this->assertSame('18.00', $item->fresh()->proposed_unit_price);
    }

    #[TestWith([100, false, null])]
    #[TestWith([0, false, 'out_of_stock'])]
    #[TestWith([3, false, 'insufficient_stock'])]
    #[TestWith([100, true, 'supplier_hidden'])]
    public function test_automatic_decreases_do_not_override_stock_or_supplier_availability(int $stock, bool $hidden, ?string $reason): void
    {
        [$user, $supplier, $offer, $item] = $this->basket();
        $supplier->update(['price_list_hidden' => $hidden]);
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer, '8.00', $stock));
        if ($hidden) {
            $supplier->refresh()->update(['price_list_hidden' => true]);
        }

        $this->actingAs($user)->get(route('cart'))->assertSeeText('Цена снижена: 9.00 → 7.20 TJS');

        $this->assertSame('7.20', $item->fresh()->unit_price);
        $this->assertSame($reason, $item->fresh()->availability_reason);
        $this->assertSame($reason === null, $item->fresh()->isEligible());
        $this->assertSame(7, $item->fresh()->quantity);
        $this->assertDatabaseHas('audit_events', ['event' => 'cart.price_decreased', 'subject_id' => $item->id]);
    }

    public function test_accepting_a_price_does_not_accept_insufficient_stock(): void
    {
        [$user, $supplier, $offer, $item] = $this->basket();
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer, '20.00', 3));

        $this->actingAs($user)->post(route('cart.items.accept-price', $item), ['offer_id' => $item->fresh()->offer_id, 'proposed_unit_price' => '18.00'])->assertRedirect();

        $this->assertSame('insufficient_stock', $item->fresh()->availability_reason);
        $this->assertFalse($item->fresh()->isEligible());
        $this->assertSame(7, $item->fresh()->quantity);
        $this->patchJson(route('cart.update', $item), ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('availability.0.warning', null)
            ->assertJsonPath('cart.excluded_count', 0)
            ->assertJsonPath('cart.total', '54.00');
        $this->assertTrue($item->fresh()->isEligible());
        $this->assertDatabaseHas('audit_events', ['event' => 'cart.item_available', 'subject_id' => $item->id]);
    }

    #[TestWith(['checkout'])]
    #[TestWith(['archive'])]
    #[TestWith(['share'])]
    #[TestWith(['pdf'])]
    #[TestWith(['excel'])]
    public function test_pending_only_baskets_cannot_be_sent_or_exported(string $action): void
    {
        config()->set('orders.placement_enabled', true);
        [$user, $supplier, $offer, $item] = $this->basket();
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer, '20.00'));
        $this->actingAs($user);

        if (in_array($action, ['pdf', 'excel'], true)) {
            $this->getJson(route('cart.suppliers.'.$action, $supplier))->assertUnprocessable();
        } else {
            $this->postJson(route('cart.suppliers.'.$action, $supplier), ['shared_via' => 'clipboard'])->assertUnprocessable();
        }

        $this->assertSame(7, $item->fresh()->quantity);
        $this->assertSame('9.00', $item->fresh()->unit_price);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('supplier_requests', 0);
    }

    public function test_acceptance_requires_authentication_and_a_current_account_mode(): void
    {
        [$user, $supplier, $offer, $item] = $this->basket();
        app(ImportActivator::class)->activate($this->replacement($supplier, $offer, '20.00'));
        $payload = ['offer_id' => $item->fresh()->offer_id, 'proposed_unit_price' => '18.00'];
        $this->post(route('logout'))->assertRedirect();

        $this->post(route('cart.items.accept-price', $item), $payload)->assertRedirect(route('login'));
        config()->set('account-modes.enabled', true);
        $this->actingAs($user)->get(route('cart'))->assertOk();
        $this->postJson(route('cart.items.accept-price', $item), $payload + ['_mode_version' => 'stale-mode'])->assertStatus(409);

        $this->assertSame('9.00', $item->fresh()->unit_price);
        $this->assertSame('18.00', $item->fresh()->proposed_unit_price);
        $this->assertDatabaseMissing('audit_events', ['event' => 'cart.price_accepted']);
    }

    /** @return array{User, Organization, Offer, CartItem} */
    private function basket(): array
    {
        $user = User::factory()->pharmacy(Organization::factory()->pharmacy()->create())->create();
        Subscription::factory()->for($user)->create();
        $supplier = Organization::factory()->wholesaler()->create(['default_discount_percent' => '10.00']);
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed, 'inventory_at' => null]);
        $supplier->update(['active_price_list_import_id' => $import->id]);
        $product = SupplierProduct::factory()->for($supplier, 'supplier')->create();
        $offer = Offer::factory()->for($supplier, 'organization')->create(['medicine_id' => $product->medicine_id, 'supplier_product_id' => $product->id, 'price_list_import_id' => $import->id, 'quantity' => 100, 'price' => '10.00']);
        $this->actingAs($user)->post(route('cart.add', $offer), ['quantity' => 7])->assertRedirect();

        return [$user, $supplier, $offer, Cart::where('user_id', $user->id)->sole()->items()->sole()];
    }

    private function replacement(Organization $supplier, Offer $offer, string $price = '10.00', ?int $quantity = 100, bool $matches = true): PriceListImport
    {
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Preview, 'inventory_at' => null]);
        PriceListImportRow::create([
            'price_list_import_id' => $import->id, 'source_row' => 1, 'raw_values' => [],
            'supplier_product_id' => $matches ? $offer->supplier_product_id : null,
            'medicine_id' => $matches ? $offer->medicine_id : null,
            'parsed_values' => ['name' => 'Replacement', 'normalized_name' => 'replacement', 'price' => $price, 'quantity' => $quantity, 'total' => null, 'expiration' => null],
            'disposition' => PriceListRowDisposition::Valid, 'planned_action' => PriceListRowAction::Update,
            'errors' => [], 'warnings' => [],
        ]);

        return $import;
    }
}
