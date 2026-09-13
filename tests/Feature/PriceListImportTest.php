<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Jobs\PreparePriceListImport;
use App\Models\Medicine;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\ProductCategoryRuleSet;
use App\Models\SupplierImportProfile;
use App\Models\User;
use App\Services\PriceList\ImportActivator;
use App\Services\PriceList\ProfileValidator;
use App\Services\PriceList\ValueNormalizer;
use App\Services\PriceList\WorkbookReader;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PriceListImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_supplier_upload_is_private_deduplicated_and_queued(): void
    {
        Storage::fake('local');
        Queue::fake([PreparePriceListImport::class]);
        $supplier = Organization::factory()->wholesaler()->create();
        $user = User::factory()->wholesaler($supplier)->create();
        SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['file_type' => 'csv', 'configuration' => ProfileValidator::defaults()]);
        $content = "name,price\nАспирин,12.50\n";

        $first = $this->actingAs($user)->post(route('price-list-imports.store'), ['file' => UploadedFile::fake()->createWithContent('prices.csv', $content)]);
        $second = $this->actingAs($user)->post(route('price-list-imports.store'), ['file' => UploadedFile::fake()->createWithContent('prices.csv', $content)]);

        $first->assertRedirect();
        $second->assertRedirect();
        $this->assertSame(1, PriceListImport::count());
        $this->assertNotNull(PriceListImport::first()->product_category_rule_set_id);
        $this->assertSame('published', ProductCategoryRuleSet::first()->status);
        Queue::assertPushed(PreparePriceListImport::class, 1);
        Storage::disk('local')->assertExists(PriceListImport::first()->file_path);
    }

    public function test_other_supplier_receives_not_found_for_import(): void
    {
        $owner = Organization::factory()->wholesaler()->create();
        $other = User::factory()->wholesaler(Organization::factory()->wholesaler()->create())->create();
        $import = PriceListImport::factory()->for($owner, 'supplier')->create();

        $this->actingAs($other)->get(route('price-list-imports.show', $import))->assertNotFound();
    }

    public function test_activation_atomically_replaces_current_offers_and_preserves_history(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $actor = User::factory()->wholesaler($supplier)->create();
        $oldImport = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed, 'inventory_at' => '2026-09-10']);
        $medicine = Medicine::factory()->create(['name' => 'Старый', 'normalized_name' => 'старый']);
        $oldOffer = $oldImport->offers()->create(['organization_id' => $supplier->id, 'medicine_id' => $medicine->id, 'price' => 10, 'stock' => 1, 'quantity' => 1, 'is_active' => true]);
        $supplier->update(['active_price_list_import_id' => $oldImport->id]);
        $newImport = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Preview, 'inventory_at' => '2026-09-11', 'profile_snapshot' => ProfileValidator::defaults(), 'valid_rows' => 1]);
        PriceListImportRow::create(['price_list_import_id' => $newImport->id, 'source_row' => 4, 'raw_values' => [], 'parsed_values' => ['name' => 'Новый', 'normalized_name' => 'новый', 'normalized_sku' => null, 'sku' => null, 'price' => 20, 'quantity' => null, 'total' => null, 'expiration' => null], 'disposition' => PriceListRowDisposition::Valid, 'planned_action' => PriceListRowAction::Create, 'errors' => [], 'warnings' => []]);

        app(ImportActivator::class)->activate($newImport, $actor);

        $this->assertSame(PriceListImportStatus::Superseded, $oldImport->fresh()->status);
        $this->assertFalse($oldOffer->fresh()->is_active);
        $this->assertSame($newImport->id, $supplier->fresh()->active_price_list_import_id);
        $this->assertTrue($newImport->offers()->first()->is_active);
        $this->assertDatabaseHas('offers', ['id' => $oldOffer->id]);
    }

    public function test_csv_processing_builds_preview_and_skips_exact_duplicate(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $profile = SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['file_type' => 'csv', 'configuration' => ProfileValidator::defaults()]);
        $path = 'price-list-imports/'.$supplier->id.'/fixture.csv';
        Storage::disk('local')->put($path, "name,price\nАспирин,12.50\nАспирин,12.50\n\n\n");
        $import = PriceListImport::create(['supplier_organization_id' => $supplier->id, 'supplier_import_profile_id' => $profile->id, 'profile_snapshot' => $profile->configuration, 'source_type' => 'manual', 'file_path' => $path, 'original_filename' => 'fixture.csv', 'mime_type' => 'text/csv', 'file_size' => 50, 'sha256' => hash('sha256', 'fixture'), 'status' => PriceListImportStatus::Pending, 'summary' => []]);

        (new PreparePriceListImport($import))->handle(app(WorkbookReader::class), app(ValueNormalizer::class));

        $this->assertSame(PriceListImportStatus::Preview, $import->fresh()->status);
        $this->assertSame(1, $import->fresh()->valid_rows);
        $this->assertSame(1, $import->fresh()->skipped_rows);
        $this->assertDatabaseHas('price_list_import_rows', ['price_list_import_id' => $import->id, 'source_row' => 3, 'disposition' => PriceListRowDisposition::Skipped->value]);
    }
}
