<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Jobs\CommitPriceListImport;
use App\Jobs\MaterializePriceListImportChunk;
use App\Jobs\SynchronizeSupplier;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\SupplierImportProfile;
use App\Models\SupplierProduct;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use App\Models\User;
use App\Services\ExternalPriceWorkbook;
use App\Services\NewestSupplierCandidates;
use App\Services\PriceList\CatalogFingerprint;
use App\Services\PriceList\ImportActivator;
use App\Services\SupplierSourcePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupplierSynchronizationWorkspaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_new_admin_created_supplier_has_no_implicit_legacy_email_authorization(): void
    {
        $this->actingAs($this->admin())->post(route('admin.suppliers.store'), ['name' => 'Новый поставщик'])->assertRedirect();
        $supplier = Organization::where('name', 'Новый поставщик')->firstOrFail();
        $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->firstOrFail();

        $this->assertTrue($configuration->usesNewestAvailable());
        $this->assertSame([], $configuration->enabledSources());
        $this->assertFalse($configuration->automatic_enabled);
        $this->assertFalse($configuration->settings_confirmed);
        $this->assertSame(60, $configuration->fetch_interval_minutes);
    }

    public function test_dashboard_defaults_to_suppliers_and_filters_attention_without_connection_forms(): void
    {
        $this->freezeTime();
        $supplier = Organization::factory()->wholesaler()->create(['name' => 'Поставщик для проверки']);
        SupplierSynchronization::factory()->for($supplier, 'supplier')->create(['automatic_enabled' => true, 'paused' => false, 'next_check_at' => now()->subHour()]);
        $this->actingAs($this->admin())->get(route('admin.supplier-sync.index', ['supplier_status' => 'attention']))
            ->assertSee($supplier->name)->assertSee('Проверка задерживается')->assertDontSee('Телефон / логин iCorp');
        $this->get(route('admin.supplier-sync.index', ['supplier_status' => 'paused']))->assertDontSee($supplier->name);
    }

    public function test_workspace_and_json_share_active_source_mode_and_progress(): void
    {
        [$config, $partner] = $this->configured();
        $active = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Completed]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        $this->actingAs($this->admin())->get(route('admin.supplier-sync.show', $config->supplier))
            ->assertSee('Самый новый доступный прайс')->assertSee('Активный источник:')->assertSee('data-import-progress', false)->assertDontSee('Выбранный источник: Не выбран');
        $this->getJson(route('admin.supplier-sync.show', $config->supplier))->assertJsonPath('active_source', $active->source_type->label())
            ->assertJsonPath('publication', '13.11.2026 11:00')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_enable_explicitly_opts_in_starts_one_run_and_preserves_interval(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        [$config] = $this->configured();
        $config->update(['workflow_policy' => null, 'automatic_enabled' => false, 'fetch_interval_minutes' => 180]);
        $data = $config->only(['selection_mode', 'automatic_enabled', 'paused', 'fetch_interval_minutes', 'freshness_hours', 'sources']) + ['expected_version' => $config->version, 'action' => 'enable'];
        $this->actingAs($this->admin())->put(route('admin.supplier-import-profiles.settings', $config->supplier), $data)->assertSessionHasNoErrors();
        $this->assertTrue($config->fresh()->reviewsConflicts());
        $this->assertTrue($config->fresh()->automatic_enabled);
        $this->assertSame(180, $config->fresh()->fetch_interval_minutes);
        $this->assertSame(1, SupplierSyncRun::where('active_supplier_id', $config->supplier_organization_id)->count());
        Queue::assertPushed(SynchronizeSupplier::class, 1);
    }

    public function test_saving_settings_cannot_bypass_the_explicit_enable_action(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        [$config] = $this->configured();
        $config->update(['automatic_enabled' => false]);
        $data = $config->only(['selection_mode', 'paused', 'fetch_interval_minutes', 'freshness_hours', 'sources'])
            + ['automatic_enabled' => true, 'expected_version' => $config->version, 'action' => 'save'];

        $this->actingAs($this->admin())->putJson(route('admin.supplier-import-profiles.settings', $config->supplier), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('automatic_enabled');

        $this->assertFalse($config->fresh()->automatic_enabled);
        $this->assertSame($config->version, $config->fresh()->version);
        Queue::assertNothingPushed();
    }

    public function test_connecting_a_mapping_does_not_enable_its_source_or_automation(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $partner = ExternalSupplierPartner::factory()->create(['supplier_organization_id' => null]);
        $this->actingAs($this->admin())->post(route('admin.supplier-sync.workspace-mapping', $supplier), ['partner_id' => $partner->id, 'confirmed' => 1])
            ->assertRedirect(route('admin.supplier-sync.show', [$supplier, 'tab' => 'sources']));
        $this->assertSame($supplier->id, $partner->fresh()->supplier_organization_id);
        $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->firstOrFail();
        $this->assertFalse($configuration->automatic_enabled);
        $this->assertSame([], $configuration->enabledSources());
    }

    #[TestWith(['2026-11-12', null, 'day', '2026-11-12 00:00:00'])]
    #[TestWith(['2026-11-12T10:30', null, 'minute', '2026-11-12 10:30:00'])]
    #[TestWith(['2026-11-12T10:30:45', 'day', 'day', '2026-11-12 00:00:00'])]
    public function test_date_confirmation_preserves_actual_precision(string $date, ?string $requestedPrecision, string $precision, string $stored): void
    {
        Bus::fake();
        [$config, $partner] = $this->configured();
        $import = $this->candidate($config, $partner, ['source_published_at' => null, 'source_date_precision' => null]);
        $data = ['published_at' => $date, 'evidence' => 'Дата из письма поставщика'];
        if ($requestedPrecision) {
            $data['precision'] = $requestedPrecision;
        }
        $this->actingAs($this->admin())->post(route('admin.supplier-sync.date', $import), $data)->assertSessionHasNoErrors();
        $this->assertSame($precision, $import->fresh()->source_date_precision);
        $this->assertSame($stored, $import->fresh()->source_published_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_sync.date_confirmed', 'subject_id' => $import->id]);
        Bus::assertChained([CommitPriceListImport::class]);
    }

    public function test_equal_date_different_content_requires_review_including_same_source(): void
    {
        [$config, $partner] = $this->configured();
        $active = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Completed]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        $candidate = $this->candidate($config, $partner);
        $this->assertSame('review_equal_date_conflict', app(SupplierSourcePolicy::class)->reason($candidate));
        $config->update(['workflow_policy' => ['version' => 1, 'tie_source' => 'pricelist_tj']]);
        $this->assertSame('review_equal_date_conflict', app(SupplierSourcePolicy::class)->reason($candidate));
        $candidate->update(['source_published_at' => now()]);
        $this->assertNull(app(SupplierSourcePolicy::class)->reason($candidate));
    }

    public function test_normalized_catalogs_deduplicate_across_sources_and_preserve_duplicate_multiplicity(): void
    {
        [$config, $partner] = $this->configured();
        $active = $this->candidate($config, $partner, ['source_type' => 'email', 'status' => PriceListImportStatus::Completed]);
        $candidate = $this->candidate($config, $partner);
        $values = ['name' => 'Аспирин', 'normalized_name' => 'аспирин', 'price' => '10.00', 'quantity' => '4.0', 'expiration' => '2027-01-01'];
        PriceListImportRow::factory()->for($active, 'import')->create(['parsed_values' => $values]);
        $row = PriceListImportRow::factory()->for($candidate, 'import')->create(['parsed_values' => array_replace($values, ['price' => '10', 'quantity' => '4'])]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        $this->assertSame('unchanged_content', app(SupplierSourcePolicy::class)->reason($candidate));
        PriceListImportRow::factory()->for($candidate, 'import')->create(['parsed_values' => $values]);
        $this->assertNotSame(app(CatalogFingerprint::class)->calculate($active), app(CatalogFingerprint::class)->calculate($candidate));
        $row->update(['parsed_values' => array_replace($values, ['quantity' => '5'])]);
        $this->assertSame('review_equal_date_conflict', app(SupplierSourcePolicy::class)->reason($candidate));
    }

    public function test_conflict_choice_is_audited_and_invalidated_by_a_configuration_change(): void
    {
        Bus::fake();
        [$config, $partner] = $this->configured();
        $active = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Completed]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        $candidate = $this->candidate($config, $partner);
        $token = app(NewestSupplierCandidates::class)->comparisonToken($candidate, $config);
        $this->actingAs($this->admin())->post(route('admin.supplier-sync.resolve-conflict', $candidate), ['comparison_token' => $token, 'evidence' => 'Поставщик подтвердил исправленные цены'])
            ->assertSessionHasNoErrors();
        $this->assertNull(app(SupplierSourcePolicy::class)->reason($candidate->fresh()));
        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_sync.conflict_resolved', 'subject_id' => $candidate->id]);
        $config->increment('version');
        $this->assertSame('configuration_changed', app(SupplierSourcePolicy::class)->reason($candidate->fresh()));
        Bus::assertChained([CommitPriceListImport::class]);
    }

    public function test_stale_conflict_review_and_non_admin_write_are_rejected(): void
    {
        [$config, $partner] = $this->configured();
        $active = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Completed]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        $candidate = $this->candidate($config, $partner);
        $token = app(NewestSupplierCandidates::class)->comparisonToken($candidate, $config);
        $candidate->update(['sha256' => str_repeat('f', 64)]);
        $data = ['comparison_token' => $token, 'evidence' => 'Проверено'];
        $this->actingAs($this->admin())->post(route('admin.supplier-sync.resolve-conflict', $candidate), $data)->assertConflict();
        $this->assertArrayNotHasKey('conflict_review', $candidate->fresh()->source_metadata);
        $this->actingAs(User::factory()->create())->post(route('admin.supplier-sync.resolve-conflict', $candidate), $data)->assertForbidden();
    }

    public function test_tie_preference_only_applies_to_exact_dates_and_newer_always_wins(): void
    {
        [$config, $partner] = $this->configured();
        $config->update(['workflow_policy' => ['version' => 1, 'tie_source' => 'pricelist_tj']]);
        $active = $this->candidate($config, $partner, ['source_type' => 'icorp', 'status' => PriceListImportStatus::Completed]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        $candidate = $this->candidate($config, $partner);
        $this->assertNull(app(SupplierSourcePolicy::class)->reason($candidate));
        $candidate->update(['source_published_at' => now()->subHours(2)]);
        $this->assertSame('older_than_active', app(SupplierSourcePolicy::class)->reason($candidate));
        $config->update(['workflow_policy' => ['version' => 1, 'tie_source' => 'icorp']]);
        $candidate->update(['source_published_at' => now()]);
        $this->assertNull(app(SupplierSourcePolicy::class)->reason($candidate));
    }

    #[TestWith(['price', '11.00'])]
    #[TestWith(['quantity', '5.00'])]
    #[TestWith(['expiration', '2028-01-01'])]
    #[TestWith(['batch', 'another-batch'])]
    public function test_catalog_fingerprint_detects_catalog_affecting_changes(string $field, string $value): void
    {
        [$config, $partner] = $this->configured();
        $first = $this->candidate($config, $partner);
        $second = $this->candidate($config, $partner);
        $values = ['name' => 'Аспирин', 'normalized_name' => 'аспирин', 'price' => '10.00', 'quantity' => '4.00', 'expiration' => '2027-01-01', 'batch' => 'batch'];
        PriceListImportRow::factory()->for($first, 'import')->create(['parsed_values' => $values]);
        PriceListImportRow::factory()->for($second, 'import')->create(['parsed_values' => array_replace($values, [$field => $value])]);

        $this->assertNotSame(app(CatalogFingerprint::class)->calculate($first), app(CatalogFingerprint::class)->calculate($second));
    }

    public function test_conflict_choice_does_not_bypass_an_independently_newer_candidate(): void
    {
        Bus::fake();
        [$config, $partner] = $this->configured();
        $active = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Completed]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        $candidate = $this->candidate($config, $partner);
        $this->candidate($config, $partner, ['source_published_at' => now()]);
        $token = app(NewestSupplierCandidates::class)->comparisonToken($candidate, $config);

        $this->actingAs($this->admin())->post(route('admin.supplier-sync.resolve-conflict', $candidate), ['comparison_token' => $token, 'evidence' => 'Выбран один из конфликтов'])->assertSessionHasNoErrors();

        $this->assertSame('newer_candidate_pending', app(SupplierSourcePolicy::class)->reason($candidate->fresh()));
        Bus::assertNothingDispatched();
    }

    public function test_reviewed_conflict_can_publish_after_materializing_new_product_identities(): void
    {
        Bus::fake();
        [$config, $partner] = $this->configured();
        $active = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Completed]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        $candidate = $this->candidate($config, $partner);
        $values = ['name' => 'Аспирин', 'normalized_name' => 'аспирин', 'price' => '10.00', 'quantity' => '4.00', 'expiration' => '2027-01-01', 'total' => null];
        PriceListImportRow::factory()->for($active, 'import')->create(['parsed_values' => $values]);
        $row = PriceListImportRow::factory()->for($candidate, 'import')->create(['parsed_values' => array_replace($values, ['price' => '11.00'])]);
        $token = app(NewestSupplierCandidates::class)->comparisonToken($candidate, $config);
        $this->actingAs($this->admin())->post(route('admin.supplier-sync.resolve-conflict', $candidate), ['comparison_token' => $token, 'evidence' => 'Исправленная цена'])->assertSessionHasNoErrors();
        Bus::assertChained([MaterializePriceListImportChunk::class, CommitPriceListImport::class]);

        $activator = app(ImportActivator::class);
        $activator->materialize($candidate->fresh(), $row->id, $row->id);
        $activator->commit($candidate->fresh());

        $this->assertSame($candidate->id, $config->supplier->fresh()->active_price_list_import_id);
        $this->assertSame(PriceListImportStatus::Completed, $candidate->fresh()->status);
    }

    public function test_mapped_product_identity_changes_catalog_fingerprint(): void
    {
        [$config, $partner] = $this->configured();
        $first = $this->candidate($config, $partner);
        $second = $this->candidate($config, $partner);
        $products = SupplierProduct::factory()->count(2)->create(['supplier_organization_id' => $config->supplier_organization_id]);
        $values = ['name' => 'Alias', 'normalized_name' => 'alias', 'price' => '10.00'];
        PriceListImportRow::factory()->for($first, 'import')->create(['parsed_values' => $values, 'supplier_product_id' => $products[0]->id]);
        PriceListImportRow::factory()->for($second, 'import')->create(['parsed_values' => $values, 'supplier_product_id' => $products[1]->id]);

        $this->assertNotSame(app(CatalogFingerprint::class)->calculate($first), app(CatalogFingerprint::class)->calculate($second));
    }

    public function test_new_supplier_workspace_starts_off_with_hourly_checks_and_no_sources_authorized(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();

        $this->actingAs($this->admin())->get(route('admin.supplier-sync.show', [$supplier, 'tab' => 'sources']))
            ->assertViewHas('configuration', fn (SupplierSynchronization $configuration): bool => ! $configuration->exists
                && $configuration->usesNewestAvailable() && ! $configuration->automatic_enabled
                && $configuration->fetch_interval_minutes === 60 && $configuration->enabledSources() === []);

        $this->assertDatabaseMissing('supplier_synchronizations', ['supplier_organization_id' => $supplier->id]);
    }

    /** @return array{SupplierSynchronization, ExternalSupplierPartner} */
    private function configured(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00', 'Asia/Dushanbe'));
        $supplier = Organization::factory()->wholesaler()->create();
        SupplierImportProfile::factory()->for($supplier, 'supplier')->create();
        $sources = SupplierSynchronization::defaultSources();
        $sources['email']['enabled'] = false;
        $sources['icorp']['enabled'] = false;
        $configuration = SupplierSynchronization::factory()->for($supplier, 'supplier')->create(['selection_mode' => 'newest_available', 'sources' => $sources,
            'settings_confirmed' => true, 'automatic_enabled' => true, 'paused' => false, 'workflow_policy' => ['version' => 1]]);
        $partner = ExternalSupplierPartner::factory()->for($supplier, 'supplier')->create(['source' => 'pricelist_tj', 'profile_active' => true, 'profile_configuration' => ExternalPriceWorkbook::profile()]);

        return [$configuration->fresh(), $partner->fresh()];
    }

    /** @param array<string, mixed> $overrides */
    private function candidate(SupplierSynchronization $configuration, ExternalSupplierPartner $partner, array $overrides = []): PriceListImport
    {
        return PriceListImport::factory()->for($configuration->supplier, 'supplier')->create(array_replace([
            'status' => PriceListImportStatus::Preview, 'source_type' => 'pricelist_tj', 'source_published_at' => now()->subHour(), 'source_date_precision' => 'second',
            'valid_rows' => 1, 'error_rows' => 0, 'total_rows' => 1, 'profile_snapshot' => ExternalPriceWorkbook::profile(),
            'source_metadata' => ['configuration_version' => $configuration->version, 'partner_id' => $partner->id, 'profile_version' => $partner->profile_version, 'publication_origin' => 'filename'],
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
    }
}
