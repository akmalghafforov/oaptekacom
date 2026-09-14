<?php

namespace Tests\Feature\Console\Commands;

use App\Models\AuditEvent;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\ProductCategoryCandidate;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgePriceListImportsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_preview_reports_imported_data_without_deleting_it(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['file_path' => 'price-list-imports/'.$supplier->id.'/preview.csv']);
        $offer = Offer::factory()->for($supplier, 'organization')->for(Medicine::factory())->create(['price_list_import_id' => $import->id]);
        PriceListImportRow::factory()->for($import, 'import')->create(['offer_id' => $offer->id]);
        Storage::disk('local')->put($import->file_path, 'name,price');

        $this->artisan('price-list-imports:purge')
            ->expectsOutputToContain('Preview only.')
            ->assertSuccessful();

        $this->assertModelExists($import);
        $this->assertModelExists($offer);
        $this->assertDatabaseCount('price_list_import_rows', 1);
        Storage::disk('local')->assertExists($import->file_path);
    }

    public function test_force_purges_imported_data_and_source_files(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $buyer = Organization::factory()->pharmacy()->create();
        $buyerUser = User::factory()->pharmacy($buyer)->create();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['file_path' => 'price-list-imports/'.$supplier->id.'/prices.csv']);
        $medicine = Medicine::factory()->create();
        $offer = Offer::factory()->for($supplier, 'organization')->for($medicine)->create(['price_list_import_id' => $import->id]);
        $row = PriceListImportRow::factory()->for($import, 'import')->create(['offer_id' => $offer->id]);
        $candidate = ProductCategoryCandidate::create(['price_list_import_id' => $import->id, 'normalized_phrase' => 'aspirin', 'occurrences' => 1, 'examples' => ['Aspirin']]);
        $cart = Cart::create(['user_id' => $buyerUser->id]);
        $cartItem = CartItem::create(['cart_id' => $cart->id, 'offer_id' => $offer->id, 'quantity' => 1, 'unit_price' => 10, 'snapshot' => []]);
        $order = Order::create(['buyer_organization_id' => $buyer->id, 'supplier_organization_id' => $supplier->id, 'status' => 'new', 'total' => 10]);
        $orderItem = OrderItem::create(['order_id' => $order->id, 'offer_id' => $offer->id, 'medicine_id' => $medicine->id, 'quantity' => 1, 'unit_price' => 10, 'snapshot' => []]);
        $auditEvent = AuditEvent::create(['event' => 'price_list_import.activated', 'subject_type' => PriceListImport::class, 'subject_id' => $import->id]);
        $supplier->update(['active_price_list_import_id' => $import->id]);
        Storage::disk('local')->put($import->file_path, 'name,price');

        $this->artisan('price-list-imports:purge', ['--force' => true])
            ->expectsOutputToContain('All imported offer data and source files have been purged.')
            ->assertSuccessful();

        $this->assertModelMissing($import);
        $this->assertModelMissing($offer);
        $this->assertModelMissing($row);
        $this->assertModelMissing($candidate);
        $this->assertModelMissing($cartItem);
        $this->assertSame(null, $supplier->fresh()->active_price_list_import_id);
        $this->assertSame(null, $orderItem->fresh()->offer_id);
        $this->assertModelExists($auditEvent);
        Storage::disk('local')->assertMissing($import->file_path);
    }

    public function test_force_succeeds_when_no_imported_data_exists(): void
    {
        $this->artisan('price-list-imports:purge', ['--force' => true])
            ->expectsOutputToContain('All imported offer data and source files have been purged.')
            ->assertSuccessful();

        $this->assertDatabaseCount('price_list_imports', 0);
        $this->assertDatabaseCount('offers', 0);
    }
}
