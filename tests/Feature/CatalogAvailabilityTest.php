<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CatalogAvailabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_zero_stock_offer_is_listed_but_cannot_be_added_to_cart(): void
    {
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();
        $supplier = Organization::factory()->wholesaler()->create();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $supplier->update(['active_price_list_import_id' => $import->id]);
        $medicine = Medicine::factory()->create(['supplier_organization_id' => $supplier->id, 'name' => 'Аспирин', 'search_text' => 'аспирин']);
        $offer = Offer::factory()->for($supplier, 'organization')->for($medicine)->create(['price_list_import_id' => $import->id, 'quantity' => 0, 'source_row' => 2]);

        $response = $this->actingAs($user)->getJson(route('catalog.search', ['q' => 'аспирин']))
            ->assertOk()
            ->assertJsonPath('has_more', false);
        $this->assertStringContainsString('Аспирин', $response->json('html'));
        $this->assertStringContainsString('Нет в наличии', $response->json('html'));
        $this->actingAs($user)->post(route('cart.add', $offer))->assertNotFound();
        $this->assertDatabaseMissing('cart_items', ['offer_id' => $offer->id]);
    }

    public function test_old_active_import_does_not_block_cart_and_catalog_shows_offer_creation_date(): void
    {
        $this->travelTo('2026-09-15 12:00:00');
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();
        $supplier = Organization::factory()->wholesaler()->create();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create([
            'status' => PriceListImportStatus::Completed,
            'created_at' => '2026-09-12 11:00:00',
            'updated_at' => '2026-09-12 11:00:00',
        ]);
        $supplier->update(['active_price_list_import_id' => $import->id]);
        $medicine = Medicine::factory()->create(['supplier_organization_id' => $supplier->id, 'name' => 'Амоксициллин', 'search_text' => 'амоксициллин']);
        $offer = Offer::factory()->for($supplier, 'organization')->for($medicine)->create([
            'price_list_import_id' => $import->id,
            'quantity' => 2,
            'created_at' => '2026-09-10 08:30:00',
            'updated_at' => '2026-09-10 08:30:00',
        ]);

        $response = $this->actingAs($user)->getJson(route('catalog.search', ['q' => 'амоксициллин', 'view' => 'list']))->assertOk();

        foreach (['desktop_rows', 'mobile_rows', 'cards'] as $fragment) {
            $html = $response->json('fragments.'.$fragment);
            $this->assertStringContainsString('Добавлен в систему: 10.09.2026', $html);
            $this->assertStringNotContainsString('Данные старше 48 часов', $html);
            $this->assertStringNotContainsString('Данные требуют обновления', $html);
            $this->assertStringNotContainsString('Требует обновления', $html);
        }

        $this->assertStringNotContainsString(' disabled=', $response->json('fragments.desktop_rows'));
        $this->actingAs($user)->post(route('cart.add', $offer))->assertSessionHas('success');
        $this->assertDatabaseHas('cart_items', ['offer_id' => $offer->id, 'quantity' => 1]);
    }

    public function test_expired_offer_can_be_added_retained_and_checked_out(): void
    {
        $this->travelTo('2026-09-15 12:00:00');
        $pharmacy = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($pharmacy)->create();
        Subscription::factory()->for($user)->create();
        $supplier = Organization::factory()->wholesaler()->create();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $supplier->update(['active_price_list_import_id' => $import->id]);
        $medicine = Medicine::factory()->create(['supplier_organization_id' => $supplier->id]);
        $offer = Offer::factory()->for($supplier, 'organization')->for($medicine)->create(['price_list_import_id' => $import->id, 'quantity' => 2, 'expires_at' => '2026-09-14']);

        $this->actingAs($user)->post(route('cart.add', $offer))->assertSessionHas('success');
        $this->actingAs($user)->get(route('cart'))->assertSee($medicine->name);

        $this->assertDatabaseHas('cart_items', ['offer_id' => $offer->id, 'quantity' => 1]);

        $this->actingAs($user)->post(route('cart.suppliers.checkout', $supplier))->assertRedirect(route('orders.index'));

        $this->assertSame($offer->id, Order::query()->sole()->items()->sole()->offer_id);
    }
}
