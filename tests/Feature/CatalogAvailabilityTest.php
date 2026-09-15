<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Models\Medicine;
use App\Models\Offer;
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

        $this->actingAs($user)->get(route('catalog'))->assertSee('Аспирин');
        $this->actingAs($user)->post(route('cart.add', $offer))->assertNotFound();
        $this->assertDatabaseMissing('cart_items', ['offer_id' => $offer->id]);
    }
}
