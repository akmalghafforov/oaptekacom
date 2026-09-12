<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Jobs\PreparePriceListImport;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierImportProfile;
use App\Models\User;
use App\Services\PriceList\ProfileValidator;
use App\Services\PriceList\ValueNormalizer;
use App\Services\PriceList\WorkbookReader;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierImportProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_uploads_private_sample_and_creates_inactive_draft(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($admin)->post(route('admin.supplier-import-profiles.sample.store', $supplier), [
            'sample' => UploadedFile::fake()->createWithContent('prices.csv', "Заголовок,Стоимость\nАспирин,12.50\n"),
            'sample_metadata' => ['path' => 'attacker-controlled.csv'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $profile = $supplier->importProfile()->firstOrFail();
        $this->assertFalse($profile->is_active);
        $this->assertSame('prices.csv', $profile->sample_metadata['filename']);
        $this->assertSame(2, $profile->sample_metadata['worksheets'][0]['highest_row']);
        $this->assertStringStartsWith('supplier-import-samples/'.$supplier->id.'/', $profile->sample_metadata['path']);
        Storage::disk('local')->assertExists($profile->sample_metadata['path']);
        Storage::disk('local')->assertMissing('attacker-controlled.csv');
    }

    public function test_replacing_sample_removes_previous_managed_file(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $route = route('admin.supplier-import-profiles.sample.store', $supplier);
        $this->actingAs($admin)->post($route, ['sample' => UploadedFile::fake()->createWithContent('first.csv', "A,B\none,1\n")]);
        $oldPath = $supplier->importProfile()->firstOrFail()->sample_metadata['path'];

        $this->actingAs($admin)->post($route, ['sample' => UploadedFile::fake()->createWithContent('second.csv', "A,B\ntwo,2\n")])->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($supplier->importProfile()->firstOrFail()->sample_metadata['path']);
    }

    public function test_mapper_renders_columns_rows_escaped_values_and_truncation_notice(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $rows = ['Name,Price', "<script>alert('x')</script>,10"];
        for ($row = 3; $row <= 101; $row++) {
            $rows[] = "Product {$row},{$row}";
        }
        $this->actingAs($admin)->post(route('admin.supplier-import-profiles.sample.store', $supplier), ['sample' => UploadedFile::fake()->createWithContent('prices.csv', implode("\n", $rows))]);

        $response = $this->actingAs($admin)->get(route('admin.supplier-import-profiles.edit', $supplier));

        $response->assertSee('Столбец A')->assertSee('Столбец B')->assertSee('С строки 100')->assertSee('Показаны первые 100 строк из 101')->assertSee('&lt;script&gt;', false)->assertDontSee("<script>alert('x')</script>", false);
    }

    public function test_unreadable_and_over_dimensioned_samples_are_rejected_and_cleaned_up(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $route = route('admin.supplier-import-profiles.sample.store', $supplier);

        $this->actingAs($admin)->post($route, ['sample' => UploadedFile::fake()->createWithContent('bad.xlsx', 'not a workbook')])->assertSessionHasErrors('sample');
        $columns = implode(',', array_fill(0, 65, 'value'));
        $this->actingAs($admin)->post($route, ['sample' => UploadedFile::fake()->createWithContent('wide.csv', $columns)])->assertSessionHasErrors('sample');
        config(['price-list-imports.max_rows' => 2]);
        $this->actingAs($admin)->post($route, ['sample' => UploadedFile::fake()->createWithContent('tall.csv', "one\ntwo\nthree\n")])->assertSessionHasErrors('sample');
        $this->actingAs($admin)->post($route, ['sample' => UploadedFile::fake()->create('large.csv', 20481, 'text/csv')])->assertSessionHasErrors('sample');

        $this->assertSame([], Storage::disk('local')->allFiles('supplier-import-samples/'.$supplier->id));
        $this->assertDatabaseMissing('supplier_import_profiles', ['supplier_organization_id' => $supplier->id]);
    }

    public function test_profile_requires_sample_required_mappings_and_sku_for_strict_matching(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($admin)->put(route('admin.supplier-import-profiles.update', $supplier), $this->payload())->assertSessionHasErrors('sample');
        $this->actingAs($admin)->post(route('admin.supplier-import-profiles.sample.store', $supplier), ['sample' => UploadedFile::fake()->createWithContent('prices.csv', "A,B,C\nname,sku,price\n")]);
        $profile = $supplier->importProfile()->firstOrFail();

        $this->actingAs($admin)->put(route('admin.supplier-import-profiles.update', $supplier), $this->payload(['column_mappings' => ['A' => 'name', 'B' => 'ignore', 'C' => 'ignore']]))->assertSessionHasErrors('column_mappings');
        $this->actingAs($admin)->put(route('admin.supplier-import-profiles.update', $supplier), $this->payload(['matching_strategy' => 'sku', 'column_mappings' => ['A' => 'name', 'B' => 'ignore', 'C' => 'price']]))->assertSessionHasErrors('column_mappings');
        $this->actingAs($admin)->put(route('admin.supplier-import-profiles.update', $supplier), $this->payload(['column_mappings' => ['A' => 'name', 'B' => 'name', 'C' => 'price']]))->assertSessionHasErrors('column_mappings');
        $this->assertFalse($profile->fresh()->is_active);
    }

    public function test_profile_rejects_unavailable_worksheet_unknown_column_and_hidden_starting_row(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $this->actingAs($admin)->post(route('admin.supplier-import-profiles.sample.store', $supplier), ['sample' => UploadedFile::fake()->createWithContent('prices.csv', "Name,Price\nAspirin,10\n")]);
        $profile = $supplier->importProfile()->firstOrFail();

        $this->actingAs($admin)->put(route('admin.supplier-import-profiles.update', $supplier), $this->payload(['worksheet' => 'Missing']))->assertSessionHasErrors('worksheet');
        $this->actingAs($admin)->put(route('admin.supplier-import-profiles.update', $supplier), $this->payload(['column_mappings' => ['A' => 'name', 'Z' => 'price']]))->assertSessionHasErrors('column_mappings');
        $this->actingAs($admin)->put(route('admin.supplier-import-profiles.update', $supplier), $this->payload(['data_row' => 3]))->assertSessionHasErrors('data_row');
        $this->actingAs($admin)->getJson(route('admin.supplier-import-profiles.sample.preview', [$supplier, 'worksheet' => 'Missing']))->assertUnprocessable()->assertJsonValidationErrors('worksheet');

        $this->assertFalse($profile->fresh()->is_active);
    }

    public function test_valid_mapping_is_inverted_and_preserves_unexposed_configuration(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $this->actingAs($admin)->post(route('admin.supplier-import-profiles.sample.store', $supplier), ['sample' => UploadedFile::fake()->createWithContent('prices.csv', "Title,,\nSKU,Price,Name\nX-1,12.50,Aspirin\n")]);
        $profile = $supplier->importProfile()->firstOrFail();
        $profile->update(['configuration' => array_replace($profile->configuration, ['keep_exact_duplicates' => true])]);

        $this->actingAs($admin)->put(route('admin.supplier-import-profiles.update', $supplier), $this->payload([
            'data_row' => 3,
            'matching_strategy' => 'sku',
            'column_mappings' => ['A' => 'sku', 'B' => 'price', 'C' => 'name'],
            'sample_metadata' => ['path' => 'attacker-controlled.csv'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $profile = $profile->fresh();
        $this->assertTrue($profile->is_active);
        $this->assertSame(['name' => 'C', 'price' => 'B', 'sku' => 'A'], $profile->configuration['mapping']);
        $this->assertSame('Worksheet', $profile->configuration['worksheet']);
        $this->assertSame(2, $profile->configuration['header_row']);
        $this->assertSame(3, $profile->configuration['data_row']);
        $this->assertTrue($profile->configuration['keep_exact_duplicates']);
        $this->assertNotSame('attacker-controlled.csv', $profile->sample_metadata['path']);
    }

    public function test_saved_non_default_mapping_and_starting_row_drive_real_import(): void
    {
        Storage::fake('local');
        $supplier = Organization::factory()->wholesaler()->create();
        $profile = SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['file_type' => 'csv', 'configuration' => array_replace(ProfileValidator::defaults(), ['header_row' => 2, 'data_row' => 3, 'mapping' => ['name' => 'C', 'price' => 'B']])]);
        $path = 'price-list-imports/'.$supplier->id.'/mapped.csv';
        Storage::disk('local')->put($path, "Report,,\nCode,Cost,Product\nX1,19.75,Аспирин\n");
        $import = PriceListImport::factory()->for($supplier, 'supplier')->for($profile, 'profile')->create(['file_path' => $path, 'original_filename' => 'mapped.csv', 'profile_snapshot' => $profile->configuration, 'status' => PriceListImportStatus::Pending]);

        (new PreparePriceListImport($import))->handle(app(WorkbookReader::class), app(ValueNormalizer::class));

        $row = $import->rows()->firstOrFail();
        $this->assertSame(3, $row->source_row);
        $this->assertSame('Аспирин', $row->parsed_values['name']);
        $this->assertSame(19.75, $row->parsed_values['price']);
    }

    public function test_sample_routes_require_authentication_and_admin_role(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $wholesaler = User::factory()->wholesaler($supplier)->create();
        $url = route('admin.supplier-import-profiles.sample.store', $supplier);

        $this->post($url)->assertRedirect(route('login'));
        $this->actingAs($wholesaler)->post($url)->assertForbidden();
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Основной профиль',
            'file_type' => 'csv',
            'worksheet' => 'Worksheet',
            'data_row' => 2,
            'column_mappings' => ['A' => 'name', 'B' => 'price'],
            'sender_emails' => '',
            'decimal_separator' => '.',
            'matching_strategy' => 'name',
            'activation_mode' => 'manual',
        ], $overrides);
    }
}
