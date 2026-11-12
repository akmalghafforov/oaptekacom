<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSyncRun;
use App\Models\User;
use App\Services\ExternalPriceWorkbook;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ImportProgressTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_external_download_is_visible_before_a_file_exists(): void
    {
        $run = SupplierSyncRun::factory()->create(['active_supplier_id' => Organization::factory()->wholesaler(), 'status' => 'queued', 'configuration_snapshot' => ['selected_source' => 'icorp']]);
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));
        $this->get(route('price-list-imports.index'))->assertOk()->assertSee('Ход импорта')->assertSee('В очереди на загрузку')->assertSee('iCorp');
        $this->getJson(route('price-list-imports.index'))->assertJsonPath('entries.0.id', 'run-'.$run->id)->assertJsonPath('entries.0.active', true);
    }

    public function test_detail_and_list_show_external_processing_and_terminal_state(): void
    {
        $import = PriceListImport::factory()->create(['source_type' => 'pricelist_tj', 'status' => PriceListImportStatus::Processing, 'total_rows' => 100, 'profile_snapshot' => ExternalPriceWorkbook::profile()]);
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));
        $this->get(route('price-list-imports.show', $import))->assertOk()->assertSee('Обработано строк: 0 / 100')->assertSee('PriceList-TJ');
        $this->getJson(route('price-list-imports.show', $import))->assertJsonPath('entries.0.active', true)->assertJsonPath('entries.0.total', 100);
        $this->getJson(route('price-list-imports.index'))->assertJsonPath('entries.0.import_id', $import->id);
        $import->update(['status' => PriceListImportStatus::Completed]);
        $this->getJson(route('price-list-imports.show', $import))->assertJsonPath('entries.0.active', false)->assertJsonPath('entries.0.detail', 'Каталог опубликован.');
    }

    public function test_progress_remains_scoped_to_the_authenticated_supplier(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $other = PriceListImport::factory()->create(['source_type' => 'icorp']);
        SupplierSyncRun::factory()->create(['configuration_snapshot' => ['selected_source' => 'icorp']]);
        $user = User::factory()->wholesaler($supplier)->create();
        $this->actingAs($user)->getJson(route('price-list-imports.index'))->assertJsonCount(0, 'entries');
        $this->getJson(route('price-list-imports.show', $other))->assertNotFound();
    }
}
