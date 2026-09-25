<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Jobs\PreparePriceListImport;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\SupplierImportProfile;
use App\Models\User;
use App\Services\PriceList\ImportActivator;
use App\Services\PriceList\ProfileValidator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PriceListCanonicalProductTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_successful_duplicate_is_queued_immediately_with_audit_metadata(): void
    {
        Storage::fake('local');
        Queue::fake([PreparePriceListImport::class]);
        $supplier = Organization::factory()->wholesaler()->create();
        $user = User::factory()->wholesaler($supplier)->create();
        SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['file_type' => 'csv', 'configuration' => ProfileValidator::defaults()]);
        $content = "name,price\nАспирин,12.50\n";
        PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed, 'sha256' => hash('sha256', $content)]);

        $response = $this->actingAs($user)->post(route('price-list-imports.store'), ['file' => UploadedFile::fake()->createWithContent('prices.csv', $content)]);

        $duplicate = PriceListImport::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('price-list-imports.show', $duplicate));
        $this->assertSame(PriceListImportStatus::Pending, $duplicate->status);
        $this->assertNotNull($duplicate->duplicate_of_import_id);
        Queue::assertPushed(PreparePriceListImport::class, 1);
    }

    public function test_same_name_is_canonical_per_supplier_and_reused_for_multiple_rows(): void
    {
        $firstSupplier = Organization::factory()->wholesaler()->create();
        $secondSupplier = Organization::factory()->wholesaler()->create();
        $firstImport = $this->previewImport($firstSupplier, ['Аспирин', ' аспирин ']);
        $secondImport = $this->previewImport($secondSupplier, ['Аспирин']);

        app(ImportActivator::class)->activate($firstImport);
        app(ImportActivator::class)->activate($secondImport);

        $this->assertSame(1, Medicine::query()->where('supplier_organization_id', $firstSupplier->id)->count());
        $this->assertSame(1, Medicine::query()->where('supplier_organization_id', $secondSupplier->id)->count());
        $this->assertSame(2, $firstImport->offers()->count());
        $this->assertSame(1, $firstImport->offers()->pluck('medicine_id')->unique()->count());
    }

    public function test_current_offer_scope_uses_supplier_pointer_instead_of_active_flag(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $current = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $old = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Superseded]);
        $supplier->update(['active_price_list_import_id' => $current->id]);
        $medicine = Medicine::factory()->create(['supplier_organization_id' => $supplier->id]);
        $currentOffer = Offer::factory()->for($supplier, 'organization')->for($medicine)->create(['price_list_import_id' => $current->id, 'source_row' => 2, 'is_active' => false, 'quantity' => 1]);
        Offer::factory()->for($supplier, 'organization')->for($medicine)->create(['price_list_import_id' => $old->id, 'source_row' => 2, 'is_active' => true, 'quantity' => 1]);

        $this->assertSame([$currentOffer->id], Offer::query()->currentCatalog()->pluck('id')->all());
    }

    public function test_catalog_and_purchase_scopes_include_expired_offers_but_exclude_zero_stock_from_purchase(): void
    {
        $this->travelTo('2026-09-15 12:00:00');
        $supplier = Organization::factory()->wholesaler()->create();
        $current = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $supplier->update(['active_price_list_import_id' => $current->id]);
        $medicine = Medicine::factory()->create(['supplier_organization_id' => $supplier->id]);
        $zeroStock = Offer::factory()->for($supplier, 'organization')->for($medicine)->create(['price_list_import_id' => $current->id, 'source_row' => 2, 'quantity' => 0, 'expires_at' => '2026-09-15']);
        $inStock = Offer::factory()->for($supplier, 'organization')->for($medicine)->create(['price_list_import_id' => $current->id, 'source_row' => 3, 'quantity' => 2, 'expires_at' => '2026-09-15']);
        $expired = Offer::factory()->for($supplier, 'organization')->for($medicine)->create(['price_list_import_id' => $current->id, 'source_row' => 4, 'quantity' => 2, 'expires_at' => '2026-09-14']);

        $this->assertSame([$zeroStock->id, $inStock->id, $expired->id], Offer::query()->currentCatalog()->orderBy('id')->pluck('id')->all());
        $this->assertSame([$inStock->id, $expired->id], Offer::query()->currentAvailable()->orderBy('id')->pluck('id')->all());
    }

    /** @param list<string> $names */
    private function previewImport(Organization $supplier, array $names): PriceListImport
    {
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Preview, 'inventory_at' => now(), 'profile_snapshot' => ProfileValidator::defaults()]);
        foreach ($names as $index => $name) {
            PriceListImportRow::create([
                'price_list_import_id' => $import->id, 'source_row' => $index + 2, 'raw_values' => [],
                'parsed_values' => ['name' => $name, 'normalized_name' => 'аспирин', 'normalized_sku' => null, 'sku' => null, 'price' => 10 + $index, 'quantity' => 1, 'total' => null, 'expiration' => null],
                'disposition' => PriceListRowDisposition::Valid, 'planned_action' => PriceListRowAction::Create,
                'assigned_category' => 'Лекарственные средства', 'categorization_status' => 'matched', 'errors' => [], 'warnings' => [],
            ]);
        }

        return $import;
    }
}
