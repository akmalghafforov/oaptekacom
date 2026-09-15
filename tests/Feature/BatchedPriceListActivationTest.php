<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Jobs\MaterializePriceListImportChunk;
use App\Jobs\PreparePriceListImport;
use App\Models\AuditEvent;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\SupplierProduct;
use App\Models\SupplierProductAlias;
use App\Models\User;
use App\Services\PriceList\ActivationDispatcher;
use App\Services\PriceList\ImportActivator;
use App\Services\PriceList\ProfileValidator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BatchedPriceListActivationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_switches_catalogue_and_cleans_superseded_legacy_state(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $actor = User::factory()->wholesaler($supplier)->create();
        $old = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed, 'inventory_at' => '2026-09-10']);
        $medicine = Medicine::factory()->create(['supplier_organization_id' => $supplier->id]);
        $oldOffer = Offer::factory()->for($supplier, 'organization')->for($medicine)->create(['price_list_import_id' => $old->id, 'source_row' => 2, 'quantity' => 1, 'is_active' => true]);
        $supplier->update(['active_price_list_import_id' => $old->id]);
        $cart = Cart::create(['user_id' => $actor->id]);
        CartItem::create(['cart_id' => $cart->id, 'offer_id' => $oldOffer->id, 'quantity' => 1, 'unit_price' => 10, 'snapshot' => []]);
        $candidate = $this->previewImport($supplier, ['Аспирин', 'Ибупрофен'], '2026-09-11');

        app(ActivationDispatcher::class)->dispatch($candidate, $actor->id);

        $this->assertSame(PriceListImportStatus::Completed, $candidate->fresh()->status);
        $this->assertSame(PriceListImportStatus::Superseded, $old->fresh()->status);
        $this->assertSame($candidate->id, $supplier->fresh()->active_price_list_import_id);
        $this->assertSame(2, Offer::query()->currentCatalog()->count());
        $this->assertDatabaseHas('offers', ['id' => $oldOffer->id, 'is_active' => false]);
        $this->assertDatabaseMissing('cart_items', ['offer_id' => $oldOffer->id]);
        $this->assertDatabaseHas('audit_events', ['event' => 'price_list_import.activated', 'subject_id' => $candidate->id]);
    }

    public function test_failed_middle_chunk_keeps_current_catalogue_and_retry_is_idempotent(): void
    {
        config()->set('price-list-imports.activation_chunk_size', 1);
        $supplier = Organization::factory()->wholesaler()->create();
        $actor = User::factory()->wholesaler($supplier)->create();
        $current = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed, 'inventory_at' => '2026-09-10']);
        $supplier->update(['active_price_list_import_id' => $current->id]);
        $candidate = $this->previewImport($supplier, ['Товар 1', 'Товар 2', 'Товар 3'], '2026-09-11');
        $ids = $candidate->rows()->orderBy('id')->pluck('id');
        (new MaterializePriceListImportChunk($candidate->id, $ids[0], $ids[0], $actor->id))->handle(app(ImportActivator::class));
        (new MaterializePriceListImportChunk($candidate->id, $ids[1], $ids[1], $actor->id))->failed(new RuntimeException('injected failure'));

        $this->assertSame($current->id, $supplier->fresh()->active_price_list_import_id);
        $this->assertSame('activation_materialization', $candidate->fresh()->failure_stage);

        $this->actingAs($actor)->post(route('price-list-imports.retry', $candidate))->assertRedirect();

        $this->assertSame(PriceListImportStatus::Completed, $candidate->fresh()->status);
        $this->assertSame(3, Medicine::query()->where('supplier_organization_id', $supplier->id)->count());
        $this->assertSame(3, SupplierProduct::query()->where('supplier_organization_id', $supplier->id)->count());
        $this->assertSame(3, SupplierProductAlias::query()->where('supplier_organization_id', $supplier->id)->count());
        $this->assertSame(3, $candidate->offers()->count());
        $this->assertSame(1, AuditEvent::query()->where('event', 'price_list_import.activated')->where('subject_id', $candidate->id)->count());
    }

    public function test_competing_imports_use_inventory_order_and_duplicate_cutover_is_safe(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $older = $this->previewImport($supplier, ['Старший'], '2026-09-10');
        $newer = $this->previewImport($supplier, ['Новейший'], '2026-09-11');

        app(ImportActivator::class)->activate($older);
        app(ImportActivator::class)->activate($newer);
        $duplicate = app(ImportActivator::class)->commit($newer->fresh());

        $this->assertSame($newer->id, $supplier->fresh()->active_price_list_import_id);
        $this->assertSame(PriceListImportStatus::Superseded, $older->fresh()->status);
        $this->assertSame(PriceListImportStatus::Completed, $duplicate['import']->status);
        $this->assertSame(1, AuditEvent::query()->where('event', 'price_list_import.activated')->where('subject_id', $newer->id)->count());
    }

    public function test_materializes_four_thousand_distinct_rows_with_complete_linkage(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Preview, 'inventory_at' => '2026-09-15', 'profile_snapshot' => ProfileValidator::defaults(), 'valid_rows' => 4000]);
        $now = now();
        foreach (range(0, 7) as $batch) {
            $rows = [];
            foreach (range(1, 500) as $offset) {
                $number = ($batch * 500) + $offset;
                $name = 'Товар '.$number;
                $rows[] = ['price_list_import_id' => $import->id, 'source_row' => $number + 1,
                    'raw_values' => '[]', 'parsed_values' => json_encode(['name' => $name, 'normalized_name' => mb_strtolower($name), 'normalized_sku' => null, 'sku' => null, 'price' => 10, 'quantity' => 1, 'total' => null, 'expiration' => null], JSON_UNESCAPED_UNICODE),
                    'disposition' => PriceListRowDisposition::Valid->value, 'planned_action' => PriceListRowAction::Create->value,
                    'errors' => '[]', 'warnings' => '[]', 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('price_list_import_rows')->insert($rows);
        }

        app(ActivationDispatcher::class)->dispatch($import);

        $this->assertSame(PriceListImportStatus::Completed, $import->fresh()->status);
        $this->assertSame(4000, $import->offers()->count());
        $this->assertSame(4000, Medicine::query()->where('supplier_organization_id', $supplier->id)->count());
        $this->assertSame(4000, $import->rows()->whereNotNull('medicine_id')->whereNotNull('supplier_product_id')->whereNotNull('offer_id')->count());
    }

    public function test_preparation_failure_retry_reprocesses_the_source(): void
    {
        Queue::fake([PreparePriceListImport::class]);
        $supplier = Organization::factory()->wholesaler()->create();
        $actor = User::factory()->wholesaler($supplier)->create();
        $import = $this->previewImport($supplier, ['Товар'], '2026-09-11');
        $import->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'preparation', 'failed_at' => now(), 'failure_message' => 'failed']);

        $this->actingAs($actor)->post(route('price-list-imports.retry', $import))->assertRedirect();

        $this->assertSame(PriceListImportStatus::Pending, $import->fresh()->status);
        $this->assertSame(0, $import->rows()->count());
        Queue::assertPushed(PreparePriceListImport::class, 1);
    }

    /** @param list<string> $names */
    private function previewImport(Organization $supplier, array $names, string $inventoryAt): PriceListImport
    {
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Preview, 'inventory_at' => $inventoryAt, 'profile_snapshot' => ProfileValidator::defaults(), 'valid_rows' => count($names)]);
        foreach ($names as $index => $name) {
            PriceListImportRow::create(['price_list_import_id' => $import->id, 'source_row' => $index + 2, 'raw_values' => [],
                'parsed_values' => ['name' => $name, 'normalized_name' => mb_strtolower($name), 'normalized_sku' => null, 'sku' => null, 'price' => 10 + $index, 'quantity' => 1, 'total' => null, 'expiration' => null],
                'disposition' => PriceListRowDisposition::Valid, 'planned_action' => PriceListRowAction::Create,
                'assigned_categories' => [], 'category_candidates' => [], 'errors' => [], 'warnings' => []]);
        }

        return $import;
    }
}
