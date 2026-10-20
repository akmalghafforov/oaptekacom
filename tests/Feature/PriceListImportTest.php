<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Jobs\CommitPriceListImport;
use App\Jobs\FinalizePriceListImportPreview;
use App\Jobs\MaterializePriceListImportChunk;
use App\Jobs\PreparePriceListImport;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\ProductCategoryRuleSet;
use App\Models\Subscription;
use App\Models\SupplierImportProfile;
use App\Models\User;
use App\Services\PriceList\CategoryCandidateExtractor;
use App\Services\PriceList\ImportActivator;
use App\Services\PriceList\ProfileValidator;
use App\Services\PriceList\ValueNormalizer;
use App\Services\PriceList\WorkbookReader;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PriceListImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_sees_supplier_import_profile_statuses(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $activeSupplier = Organization::factory()->wholesaler()->create(['name' => 'A Active supplier']);
        $inactiveSupplier = Organization::factory()->wholesaler()->create(['name' => 'B Inactive supplier']);
        $supplierWithoutProfile = Organization::factory()->wholesaler()->create(['name' => 'C No profile supplier']);
        SupplierImportProfile::factory()->for($activeSupplier, 'supplier')->create(['is_active' => true]);
        SupplierImportProfile::factory()->for($inactiveSupplier, 'supplier')->create(['is_active' => false]);

        $response = $this->actingAs($admin)->get(route('price-list-imports.index'));

        $response->assertSeeInOrder([
            $activeSupplier->name,
            'Активный профиль',
            $inactiveSupplier->name,
            'Нет активного профиля',
            $supplierWithoutProfile->name,
            'Нет активного профиля',
        ]);
        $response->assertSee('border-success-border');
        $response->assertSee('bg-success-surface');
        $response->assertSee('border-danger-border');
        $response->assertSee('bg-danger-surface');
    }

    public function test_wholesaler_does_not_see_admin_supplier_profile_statuses(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $user = User::factory()->wholesaler($supplier)->create();

        $response = $this->actingAs($user)->get(route('price-list-imports.index'));

        $response->assertDontSee('Профили поставщиков');
        $response->assertDontSee('Активный профиль');
        $response->assertDontSee('Нет активного профиля');
    }

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

    public function test_activation_while_hidden_preserves_visibility_and_restoration_reveals_latest_offers(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $actor = User::factory()->wholesaler($supplier)->create();
        $oldImport = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed, 'inventory_at' => '2026-09-10']);
        $medicine = Medicine::factory()->create(['name' => 'Старый', 'normalized_name' => 'старый']);
        $oldOffer = $oldImport->offers()->create(['organization_id' => $supplier->id, 'medicine_id' => $medicine->id, 'price' => 10, 'stock' => 1, 'quantity' => 1, 'is_active' => true]);
        $supplier->update(['active_price_list_import_id' => $oldImport->id, 'price_list_hidden' => true]);
        $newImport = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Preview, 'inventory_at' => '2026-09-11', 'profile_snapshot' => ProfileValidator::defaults(), 'valid_rows' => 1]);
        PriceListImportRow::create(['price_list_import_id' => $newImport->id, 'source_row' => 4, 'raw_values' => [], 'parsed_values' => ['name' => 'Новый', 'normalized_name' => 'новый', 'normalized_sku' => null, 'sku' => null, 'price' => 20, 'quantity' => null, 'total' => null, 'expiration' => null], 'disposition' => PriceListRowDisposition::Valid, 'planned_action' => PriceListRowAction::Create, 'errors' => [], 'warnings' => []]);

        app(ImportActivator::class)->activate($newImport, $actor);

        $this->assertSame(PriceListImportStatus::Superseded, $oldImport->fresh()->status);
        $this->assertFalse($oldOffer->fresh()->is_active);
        $this->assertSame($newImport->id, $supplier->fresh()->active_price_list_import_id);
        $this->assertTrue($newImport->offers()->first()->is_active);
        $this->assertDatabaseHas('offers', ['id' => $oldOffer->id]);
        $this->assertTrue($supplier->fresh()->price_list_hidden);
        $this->assertSame(0, Offer::query()->currentCatalog()->where('organization_id', $supplier->id)->count());
        $supplier->update(['price_list_hidden' => false]);
        $this->assertSame($newImport->offers()->sole()->id, Offer::query()->currentCatalog()->where('organization_id', $supplier->id)->sole()->id);
    }

    public function test_csv_processing_activates_automatically_and_skips_exact_duplicate(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $profile = SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['file_type' => 'csv', 'configuration' => ProfileValidator::defaults()]);
        $path = 'price-list-imports/'.$supplier->id.'/fixture.csv';
        Storage::disk('local')->put($path, "name,price\nАспирин,12.50\nАспирин,12.50\n\n\n");
        $import = PriceListImport::create(['supplier_organization_id' => $supplier->id, 'supplier_import_profile_id' => $profile->id, 'profile_snapshot' => $profile->configuration, 'source_type' => 'manual', 'file_path' => $path, 'original_filename' => 'fixture.csv', 'mime_type' => 'text/csv', 'file_size' => 50, 'sha256' => hash('sha256', 'fixture'), 'status' => PriceListImportStatus::Pending, 'summary' => []]);

        (new PreparePriceListImport($import))->handle(app(WorkbookReader::class), app(ValueNormalizer::class));

        $this->assertSame(PriceListImportStatus::Completed, $import->fresh()->status);
        $this->assertSame(1, $import->fresh()->valid_rows);
        $this->assertSame(1, $import->fresh()->skipped_rows);
        $this->assertDatabaseHas('price_list_import_rows', ['price_list_import_id' => $import->id, 'source_row' => 3, 'disposition' => PriceListRowDisposition::Skipped->value]);
    }

    public function test_xls_import_publishes_comma_decimal_and_numeric_prices_while_isolating_invalid_rows(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $configuration = array_replace(ProfileValidator::defaults(), [
            'worksheet' => 'Точики',
            'header_row' => 3,
            'data_row' => 4,
            'mapping' => ['name' => 'B', 'price' => 'D'],
        ]);
        $profile = SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['file_type' => 'xls', 'configuration' => $configuration]);
        $workbook = new Spreadsheet;
        $workbook->getActiveSheet()->setTitle('Other')->fromArray([['Wrong sheet', 999]]);
        $sheet = $workbook->createSheet()->setTitle('Точики');
        $sheet->fromArray([
            [null, 'Название', null, 'Цена'],
            [null, 'Аспирин', null, '12,5'],
            [null, 'Ибупрофен', null, 'invalid'],
            [null, 'Парацетамол', null, '2,3'],
            [null, 'Амоксициллин', null, 15.75],
        ], null, 'A3');
        $sheet->setCellValueExplicit('D4', '12,5', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('D6', '2,3', DataType::TYPE_STRING);
        $path = 'fixture.xls';
        (new Xls($workbook))->save(Storage::disk('local')->path($path));
        $workbook->disconnectWorksheets();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create([
            'supplier_import_profile_id' => $profile->id,
            'profile_snapshot' => $configuration,
            'file_path' => $path,
            'original_filename' => 'fixture.xls',
            'mime_type' => 'application/vnd.ms-excel',
        ]);

        (new PreparePriceListImport($import))->handle(app(WorkbookReader::class), app(ValueNormalizer::class));

        $this->assertSame(PriceListImportStatus::Completed, $import->fresh()->status);
        $import->refresh();
        $this->assertSame(3, $import->valid_rows);
        $this->assertSame(1, $import->error_rows);
        $rows = $import->rows()->orderBy('source_row')->get()->keyBy('source_row');
        foreach ([4 => 12.5, 6 => 2.3, 7 => 15.75] as $sourceRow => $price) {
            $this->assertSame(PriceListRowDisposition::Valid, $rows[$sourceRow]->disposition);
            $this->assertSame($price, $rows[$sourceRow]->parsed_values['price']);
        }
        $this->assertSame('12,5', $rows[4]->raw_values['price']);
        $this->assertSame('2,3', $rows[6]->raw_values['price']);
        $this->assertSame('invalid', $rows[5]->raw_values['price']);
        $this->assertSame(PriceListRowDisposition::Error, $rows[5]->disposition);
        $this->assertNull($rows[5]->parsed_values['price']);
        $this->assertSame(['Цена должна быть положительным числом.'], $rows[5]->errors);
        $this->assertSame(3, $import->offers()->count());
        foreach (['Аспирин' => '12.50', 'Парацетамол' => '2.30', 'Амоксициллин' => '15.75'] as $name => $price) {
            $this->assertDatabaseHas('offers', ['price_list_import_id' => $import->id, 'source_name' => $name, 'price' => $price, 'is_active' => true]);
        }
        $this->assertDatabaseMissing('offers', ['price_list_import_id' => $import->id, 'source_name' => 'Ибупрофен']);
    }

    #[TestWith(['completed', false])]
    #[TestWith(['superseded', false])]
    #[TestWith(['completed', true])]
    public function test_replay_creates_a_new_import_with_current_profile_and_preserves_history(string $status, bool $asAdmin): void
    {
        Storage::fake('local');
        Queue::fake([PreparePriceListImport::class]);
        $supplier = Organization::factory()->wholesaler()->create();
        $actor = $asAdmin ? User::factory()->admin()->create(['two_factor_confirmed_at' => now()]) : User::factory()->wholesaler($supplier)->create();
        $configuration = array_replace(ProfileValidator::defaults(), ['mapping' => ['name' => 'B', 'price' => 'D']]);
        $profile = SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['file_type' => 'csv', 'configuration' => $configuration]);
        $content = "id,name,other,price\n1,Аспирин,,\"12,5\"\n2,Парацетамол,,\"2,3\"\n";
        Storage::disk('local')->put('original.csv', $content);
        $original = PriceListImport::factory()->for($supplier, 'supplier')->create([
            'supplier_import_profile_id' => $profile->id,
            'status' => $status,
            'file_path' => 'original.csv',
            'original_filename' => 'original.csv',
            'mime_type' => 'text/csv',
            'sha256' => hash('sha256', $content),
            'profile_snapshot' => ProfileValidator::defaults(),
        ]);
        $originalRow = PriceListImportRow::factory()->for($original, 'import')->create();
        $originalAttributes = $original->refresh()->getAttributes();
        $this->actingAs($actor)->get(route('price-list-imports.show', $original))->assertSee('Обработать заново');

        $response = $this->post(route('price-list-imports.replay', $original));

        $replay = PriceListImport::query()->whereKeyNot($original->id)->sole();
        $response->assertRedirectToRoute('price-list-imports.show', $replay)->assertSessionHas('success');
        $this->assertSame($originalAttributes, $original->fresh()->getAttributes());
        $this->assertModelExists($originalRow);
        $this->assertSame($original->id, $replay->duplicate_of_import_id);
        $this->assertSame($actor->id, $replay->initiated_by);
        $this->assertSame($configuration['mapping'], $replay->profile_snapshot['mapping']);
        $this->assertSame(PriceListImportStatus::Pending, $replay->status);
        $this->assertNotSame($original->file_path, $replay->file_path);
        $this->assertSame($content, Storage::disk('local')->get($replay->file_path));

        $this->post(route('price-list-imports.replay', $original))->assertRedirectToRoute('price-list-imports.show', $replay);
        $this->assertSame(2, PriceListImport::count());
        $this->assertCount(2, Storage::disk('local')->allFiles());
        Queue::assertPushed(PreparePriceListImport::class, 1);

        (new PreparePriceListImport($replay))->handle(app(WorkbookReader::class), app(ValueNormalizer::class));

        $this->assertSame(PriceListImportStatus::Completed, $replay->fresh()->status);
        $this->assertSame(2, $replay->offers()->count());
        $this->assertDatabaseHas('offers', ['price_list_import_id' => $replay->id, 'source_name' => 'Аспирин', 'price' => '12.50', 'is_active' => true]);
        $this->assertDatabaseHas('offers', ['price_list_import_id' => $replay->id, 'source_name' => 'Парацетамол', 'price' => '2.30', 'is_active' => true]);
        $this->assertModelExists($originalRow);
        Storage::disk('local')->assertExists($original->file_path);
    }

    #[TestWith(['pending'])]
    #[TestWith(['processing'])]
    #[TestWith(['preview'])]
    #[TestWith(['committing'])]
    #[TestWith(['failed'])]
    public function test_replay_rejects_imports_that_have_not_succeeded(string $status): void
    {
        Queue::fake([PreparePriceListImport::class]);
        $supplier = Organization::factory()->wholesaler()->create();
        $actor = User::factory()->wholesaler($supplier)->create();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => $status, 'profile_snapshot' => ProfileValidator::defaults()]);

        $this->actingAs($actor)->get(route('price-list-imports.show', $import))->assertDontSee('Обработать заново');
        $this->post(route('price-list-imports.replay', $import))->assertUnprocessable();

        $this->assertSame(1, PriceListImport::count());
        Queue::assertNotPushed(PreparePriceListImport::class);
    }

    public function test_replay_rejects_other_suppliers_and_buyers(): void
    {
        Queue::fake([PreparePriceListImport::class]);
        $import = PriceListImport::factory()->create(['status' => PriceListImportStatus::Completed]);
        $otherSupplier = User::factory()->wholesaler()->create();
        $buyer = User::factory()->pharmacy()->create(['two_factor_confirmed_at' => now()]);
        Subscription::factory()->for($buyer)->create();

        $this->post(route('price-list-imports.replay', $import))->assertRedirectToRoute('login');
        $this->actingAs($otherSupplier)->post(route('price-list-imports.replay', $import))->assertNotFound();
        $this->actingAs($buyer)->post(route('price-list-imports.replay', $import))->assertNotFound();

        $this->assertSame(1, PriceListImport::count());
        Queue::assertNotPushed(PreparePriceListImport::class);
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_replay_requires_an_active_profile_and_available_source_file(bool $activeProfile): void
    {
        Storage::fake('local');
        Queue::fake([PreparePriceListImport::class]);
        $supplier = Organization::factory()->wholesaler()->create();
        $actor = User::factory()->wholesaler($supplier)->create();
        $profile = SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['is_active' => $activeProfile]);
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['supplier_import_profile_id' => $profile->id, 'status' => PriceListImportStatus::Completed]);

        $this->actingAs($actor)->post(route('price-list-imports.replay', $import))->assertSessionHasErrors([
            'file' => $activeProfile ? 'Исходный файл импорта недоступен. Загрузите файл заново.' : 'Для поставщика не настроен активный профиль импорта.',
        ]);

        $this->assertSame(1, PriceListImport::count());
        $this->assertSame(PriceListImportStatus::Completed, $import->fresh()->status);
        Queue::assertNotPushed(PreparePriceListImport::class);
    }

    public function test_import_stores_expired_dates_and_treats_undetected_dates_as_null(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-09-15 12:00:00');
        $supplier = Organization::factory()->wholesaler()->create();
        $configuration = array_replace(ProfileValidator::defaults(), [
            'mapping' => ['name' => 'A', 'price' => 'B', 'expiration' => 'C'],
        ]);
        $profile = SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['file_type' => 'csv', 'configuration' => $configuration]);
        $path = 'price-list-imports/'.$supplier->id.'/expiration.csv';
        Storage::disk('local')->put($path, "name,price,expiration\nПросроченный,12.50,14.09.2026\nБез срока,13.50,\nНеизвестный,14.50,неизвестно\n");
        $import = PriceListImport::create(['supplier_organization_id' => $supplier->id, 'supplier_import_profile_id' => $profile->id, 'profile_snapshot' => $configuration, 'source_type' => 'manual', 'file_path' => $path, 'original_filename' => 'expiration.csv', 'mime_type' => 'text/csv', 'file_size' => 100, 'sha256' => hash('sha256', 'expiration'), 'status' => PriceListImportStatus::Pending, 'summary' => []]);

        (new PreparePriceListImport($import))->handle(app(WorkbookReader::class), app(ValueNormalizer::class));

        $this->assertSame(PriceListImportStatus::Completed, $import->fresh()->status);
        $this->assertSame('2026-09-14', Offer::query()->where('source_name', 'Просроченный')->sole()->expires_at->toDateString());
        $this->assertNull(Offer::query()->where('source_name', 'Без срока')->sole()->expires_at);
        $this->assertNull(Offer::query()->where('source_name', 'Неизвестный')->sole()->expires_at);
    }

    public function test_finalization_ignores_legacy_manual_thresholds_and_queues_activation(): void
    {
        Queue::fake([MaterializePriceListImportChunk::class, CommitPriceListImport::class]);
        $supplier = Organization::factory()->wholesaler()->create();
        $configuration = array_replace(ProfileValidator::defaults(), [
            'activation_mode' => 'manual',
            'automatic' => ['minimum_valid_rows' => 100, 'maximum_error_rows' => 0, 'maximum_error_percentage' => 0],
        ]);
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Processing, 'profile_snapshot' => $configuration, 'total_rows' => 2]);
        PriceListImportRow::factory()->for($import, 'import')->create(['disposition' => PriceListRowDisposition::Valid]);
        PriceListImportRow::factory()->for($import, 'import')->create(['source_row' => 3, 'disposition' => PriceListRowDisposition::Error]);

        (new FinalizePriceListImportPreview($import))->handle(app(CategoryCandidateExtractor::class));

        $this->assertSame(PriceListImportStatus::Preview, $import->fresh()->status);
        Queue::assertPushed(MaterializePriceListImportChunk::class, 1);
    }

    public function test_import_with_no_processable_rows_fails_automatically(): void
    {
        Queue::fake([MaterializePriceListImportChunk::class, CommitPriceListImport::class]);
        $supplier = Organization::factory()->wholesaler()->create();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Processing, 'total_rows' => 1]);
        PriceListImportRow::factory()->for($import, 'import')->create(['disposition' => PriceListRowDisposition::Error]);

        (new FinalizePriceListImportPreview($import))->handle(app(CategoryCandidateExtractor::class));

        $this->assertSame(PriceListImportStatus::Failed, $import->fresh()->status);
        Queue::assertNotPushed(MaterializePriceListImportChunk::class);
    }
}
