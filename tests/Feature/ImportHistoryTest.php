<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSyncAttempt;
use App\Models\SupplierSyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ImportHistoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_default_and_unknown_tabs_show_only_mail_and_manual_uploads(): void
    {
        $manual = PriceListImport::factory()->create(['original_filename' => '<script>upload</script>.xlsx']);
        $mail = PriceListImport::factory()->create(['source_type' => 'email', 'original_filename' => 'mail.xlsx']);
        PriceListImport::factory()->create(['source_type' => 'icorp', 'original_filename' => 'service.xlsx']);
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));

        foreach ([[], ['tab' => 'unknown']] as $parameters) {
            $this->get(route('price-list-imports.index', $parameters))->assertOk()
                ->assertViewHas('tab', 'files')->assertSee('&lt;script&gt;upload&lt;/script&gt;.xlsx', false)
                ->assertSee('mail.xlsx')->assertDontSee('service.xlsx')->assertDontSee('Ход импорта')
                ->assertSeeInOrder(['Загрузить', 'История импорта', 'Почта и файлы', 'iCorp и PriceList.tj']);
            $entries = $this->getJson(route('price-list-imports.index', $parameters))->assertOk()->json('entries');
            $this->assertEqualsCanonicalizing([$manual->id, $mail->id], array_column($entries, 'import_id'));
        }
    }

    public function test_service_history_includes_failed_downloads_and_deduplicates_linked_runs(): void
    {
        $import = PriceListImport::factory()->create(['source_type' => 'pricelist_tj', 'original_filename' => 'external.xlsx', 'created_at' => now()->subHour()]);
        $linked = SupplierSyncRun::factory()->create(['supplier_organization_id' => $import->supplier_organization_id, 'configuration_snapshot' => ['selected_source' => 'pricelist_tj']]);
        SupplierSyncAttempt::factory()->create(['supplier_sync_run_id' => $linked->id, 'price_list_import_id' => $import->id]);
        $failed = SupplierSyncRun::factory()->create(['status' => 'failed', 'configuration_snapshot' => ['selected_source' => 'icorp']]);
        SupplierSyncAttempt::factory()->create(['supplier_sync_run_id' => $failed->id, 'source' => 'icorp', 'status' => 'failed', 'reason' => 'transport']);
        SupplierSyncRun::factory()->create(['trigger' => 'mailbox', 'configuration_snapshot' => ['selected_source' => 'email']]);
        PriceListImport::factory()->create(['source_type' => 'email', 'original_filename' => 'mail.xlsx']);
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));
        $url = route('price-list-imports.index', ['tab' => 'services']);

        $this->get($url)->assertOk()->assertViewHas('tab', 'services')->assertSee('external.xlsx')
            ->assertSee('Файл ещё не получен')->assertSee('Ошибка загрузки')->assertSee('Не удалось соединиться с сервисом.')->assertDontSee('mail.xlsx')
            ->assertSee(route('price-list-imports.show', $import))
            ->assertSee(route('admin.supplier-sync.show', [$failed->supplier_organization_id, 'tab' => 'history']));
        $this->getJson($url)->assertJsonCount(2, 'entries')->assertJsonPath('entries.0.id', 'run-'.$failed->id)
            ->assertJsonPath('entries.0.rows', null)->assertJsonPath('entries.0.filename', null)
            ->assertJsonPath('entries.1.id', 'import-'.$import->id)->assertJsonPath('entries.1.source_label', 'PriceList.tj');
    }

    public function test_both_tabs_filter_before_pagination_and_keep_the_tab_in_links(): void
    {
        $this->freezeTime();
        $supplier = Organization::factory()->wholesaler()->create();
        PriceListImport::factory()->count(21)->create(['supplier_organization_id' => $supplier->id, 'source_type' => 'email', 'created_at' => now()->subDay()]);
        PriceListImport::factory()->count(12)->create(['supplier_organization_id' => $supplier->id, 'source_type' => 'icorp', 'created_at' => now()->subHour()]);
        SupplierSyncRun::factory()->count(11)->create(['supplier_organization_id' => $supplier->id, 'configuration_snapshot' => ['selected_source' => 'pricelist_tj']]);
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));

        foreach (['files' => 1, 'services' => 3] as $tab => $remaining) {
            $url = route('price-list-imports.index', ['tab' => $tab]);
            $this->getJson($url)->assertJsonCount(20, 'entries');
            $this->getJson(route('price-list-imports.index', ['tab' => $tab, 'page' => 2]))->assertJsonCount($remaining, 'entries');
            $this->get($url)->assertSee('tab='.$tab.'&amp;page=2', false)
                ->assertSee('href="'.route('price-list-imports.index', ['tab' => $tab === 'files' ? 'services' : 'files']).'"', false);
        }
        $this->getJson(route('price-list-imports.index', ['tab' => 'services']))
            ->assertJsonPath('entries.0.id', 'run-'.SupplierSyncRun::max('id'))
            ->assertJsonPath('entries.11.source', 'icorp');
    }

    public function test_supplier_history_is_scoped_in_html_and_json_and_has_no_admin_links(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $user = User::factory()->wholesaler($supplier)->create();
        PriceListImport::factory()->create(['supplier_organization_id' => $supplier->id, 'original_filename' => 'own.xlsx']);
        $ownImport = PriceListImport::factory()->create(['supplier_organization_id' => $supplier->id, 'source_type' => 'icorp', 'original_filename' => 'own-service.xlsx']);
        $ownRun = SupplierSyncRun::factory()->create(['supplier_organization_id' => $supplier->id]);
        SupplierSyncAttempt::factory()->create(['supplier_sync_run_id' => $ownRun->id, 'source' => 'email', 'status' => 'skipped']);
        SupplierSyncAttempt::factory()->create(['supplier_sync_run_id' => $ownRun->id, 'source' => 'pricelist_tj']);
        PriceListImport::factory()->create(['original_filename' => 'private.xlsx']);
        PriceListImport::factory()->create(['source_type' => 'pricelist_tj', 'original_filename' => 'private-service.xlsx']);
        $otherRun = SupplierSyncRun::factory()->create(['configuration_snapshot' => ['selected_source' => 'icorp']]);
        $this->actingAs($user);

        foreach (['files' => 1, 'services' => 2] as $tab => $count) {
            $url = route('price-list-imports.index', ['tab' => $tab]);
            $this->get($url)->assertOk()->assertDontSee('private.xlsx')->assertDontSee('private-service.xlsx')
                ->assertDontSee($otherRun->supplier->name)->assertDontSee('/admin/supplier-sync', false);
            $entries = $this->getJson($url)->assertOk()->assertJsonCount($count, 'entries')->json('entries');
            foreach ($entries as $entry) {
                $this->assertSame($supplier->name, $entry['supplier']);
                if ($entry['import_id'] === null) {
                    $this->assertNull($entry['url']);
                } else {
                    $this->assertStringContainsString('/price-list-imports/', $entry['url']);
                }
            }
        }
        $this->get(route('price-list-imports.index', ['tab' => 'services']))->assertSee(route('price-list-imports.show', $ownImport));
    }

    public function test_empty_states_and_selected_tab_are_rendered(): void
    {
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));
        foreach (['files' => 'Прайс-листов пока нет', 'services' => 'Загрузок из сервисов пока нет'] as $tab => $title) {
            $this->get(route('price-list-imports.index', ['tab' => $tab]))->assertOk()->assertSee($title)
                ->assertSee('aria-current="page"', false);
            $this->getJson(route('price-list-imports.index', ['tab' => $tab]))->assertJsonCount(0, 'entries');
        }
    }

    #[TestWith(['authentication', 'Не удалось авторизоваться в сервисе. Активный каталог сохранён.'])]
    #[TestWith(['permission', 'Сервис отказал в доступе. Активный каталог сохранён.'])]
    #[TestWith(['extraction', 'Не удалось прочитать данные сервиса. Активный каталог сохранён.'])]
    #[TestWith(['empty_catalog', 'Сервис вернул пустой прайс-лист. Активный каталог сохранён.'])]
    #[TestWith(['service_busy', 'Сервис занят. Ожидается повторная загрузка.'])]
    public function test_service_rows_explain_download_problems(string $reason, string $explanation): void
    {
        $run = SupplierSyncRun::factory()->create(['configuration_snapshot' => ['selected_source' => 'icorp'], 'reason' => $reason]);
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));

        $this->getJson(route('price-list-imports.index', ['tab' => 'services']))
            ->assertJsonPath('entries.0.id', 'run-'.$run->id)->assertJsonPath('entries.0.detail', $explanation);
    }
}
