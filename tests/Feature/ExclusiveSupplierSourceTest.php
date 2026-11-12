<?php

namespace Tests\Feature;

use App\DataTransferObjects\StoredImportFile;
use App\Enums\PriceListImportStatus;
use App\Jobs\ProcessGmailImportReceipt;
use App\Jobs\SyncGmailImports;
use App\Jobs\SynchronizeSupplier;
use App\Models\ExternalSupplierPartner;
use App\Models\GmailImportReceipt;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierImportProfile;
use App\Models\SupplierSenderAddress;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use App\Models\User;
use App\Services\ExternalPriceWorkbook;
use App\Services\PriceList\ImportActivator;
use App\Services\SupplierPriceListIngestor;
use App\Services\SupplierSourcePolicy;
use App\Services\SupplierSynchronizationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ExclusiveSupplierSourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['price-list-imports.exclusive_sources' => true]);
    }

    public function test_settings_and_external_profiles_save_without_creating_an_email_profile_or_consuming_gmail_drafts(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $partner = ExternalSupplierPartner::factory()->create(['supplier_organization_id' => $supplier->id, 'source' => 'icorp'])->refresh();
        $draft = ['sample' => ['path' => 'private-draft.csv'], 'message' => 'message'];
        $url = route('admin.supplier-import-profiles.edit', $supplier);
        $this->actingAs($this->admin())->withSession(['gmail_profile_drafts.keep' => $draft])->get($url)
            ->assertOk()->assertSee('Единственный источник')->assertSee('нет сопоставления')->assertSee($partner->name)->assertDontSee('id="profile-form"', false);
        $this->from($url.'?gmail_draft=untouched')->put(route('admin.supplier-import-profiles.settings', $supplier), $this->settings())
            ->assertRedirect($url.'?gmail_draft=untouched')->assertSessionHasNoErrors()->assertSessionHas('gmail_profile_drafts.keep', $draft);
        $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->firstOrFail();
        $this->assertSame('icorp', $configuration->selected_source);
        $this->assertTrue($configuration->settings_confirmed);
        $this->assertFalse($configuration->automatic_enabled);
        $this->assertDatabaseMissing('supplier_import_profiles', ['supplier_organization_id' => $supplier->id]);
        $this->put(route('admin.supplier-import-profiles.external-profile', [$supplier, $partner]), $this->profile($partner))
            ->assertSessionHasNoErrors()->assertSessionHas('gmail_profile_drafts.keep', $draft);
        $this->assertTrue($partner->fresh()->profile_active);
        $this->assertSame(2, $partner->fresh()->profile_version);
        $this->assertSame(2, $configuration->fresh()->version);
        $data = $this->settings(2) + [];
        $data['automatic_enabled'] = true;
        $this->put(route('admin.supplier-import-profiles.settings', $supplier), $data)->assertSessionHasNoErrors();
        $this->assertTrue($configuration->fresh()->automatic_enabled);
    }

    #[TestWith(['guest'])]
    #[TestWith(['user'])]
    public function test_source_settings_require_an_administrator(string $role): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        if ($role === 'user') {
            $this->actingAs(User::factory()->create());
        }
        $response = $this->put(route('admin.supplier-import-profiles.settings', $supplier), $this->settings());
        $role === 'guest' ? $response->assertRedirect(route('login')) : $response->assertForbidden();
        $this->assertDatabaseMissing('supplier_synchronizations', ['supplier_organization_id' => $supplier->id]);
    }

    public function test_opening_and_saving_email_parser_does_not_confirm_synchronization(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $this->actingAs($this->admin())->get(route('admin.supplier-import-profiles.edit', $supplier))->assertOk();
        $this->assertDatabaseMissing('supplier_synchronizations', ['supplier_organization_id' => $supplier->id]);
        Storage::fake('local');
        $this->post(route('admin.supplier-import-profiles.sample.store', $supplier), ['sample' => UploadedFile::fake()->createWithContent('prices.csv', "name,price\nАспирин,12.50\n")])->assertSessionHasNoErrors();
        $this->put(route('admin.supplier-import-profiles.update', $supplier), [
            'name' => 'Профиль почты', 'file_type' => 'csv', 'worksheet' => 'Worksheet', 'data_row' => 2,
            'column_mappings' => ['A' => 'name', 'B' => 'price'], 'sender_emails' => '', 'decimal_separator' => '.', 'matching_strategy' => 'name',
        ])->assertSessionHasNoErrors();
        $this->assertTrue($supplier->importProfile()->firstOrFail()->is_active);
        $this->assertDatabaseMissing('supplier_synchronizations', ['supplier_organization_id' => $supplier->id]);
        $mail = PriceListImport::factory()->for($supplier, 'supplier')->create(['source_type' => 'email']);
        $this->assertNull(app(SupplierSourcePolicy::class)->reason($mail));
    }

    #[TestWith(['unknown'])]
    #[TestWith(['unmapped'])]
    #[TestWith(['stale'])]
    #[TestWith(['multiple'])]
    #[TestWith(['priority'])]
    #[TestWith(['unready'])]
    public function test_settings_reject_invalid_or_stale_submissions_without_overwriting(string $failure): void
    {
        [$configuration] = $this->configured();
        $data = $this->settings(1);
        match ($failure) {
            'unknown' => $data['unexpected'] = true,
            'unmapped' => $data['selected_source'] = 'pricelist_tj',
            'stale' => $data['expected_version'] = 0,
            'multiple' => $data['sources'] = SupplierSynchronization::defaultSources(),
            'priority' => $data['sources']['icorp']['priority'] = 1,
            'unready' => $data['automatic_enabled'] = true,
        };
        if ($failure === 'unready') {
            ExternalSupplierPartner::where('supplier_organization_id', $configuration->supplier_organization_id)->update(['profile_active' => false]);
        }
        $field = match ($failure) {
            'unknown' => 'unexpected', 'stale' => 'expected_version', 'multiple' => 'sources', 'priority' => 'sources.icorp.priority', default => 'selected_source',
        };
        $this->actingAs($this->admin())->putJson(route('admin.supplier-import-profiles.settings', $configuration->supplier), $data)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(1, $configuration->fresh()->version);
        $this->assertDatabaseMissing('audit_events', ['event' => 'supplier_sync.configured']);
    }

    public function test_legacy_write_adapter_rejects_obsolete_multi_source_payload(): void
    {
        [$configuration] = $this->configured();
        $data = $this->settings(1);
        unset($data['selected_source']);
        $data['sources'] = SupplierSynchronization::defaultSources();
        $this->actingAs($this->admin())->putJson(route('admin.supplier-sync.update', $configuration->supplier), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('selected_source')->assertJsonPath('errors.selected_source.0', 'Выберите один источник в профиле импорта. Резервная загрузка больше не поддерживается.');
        $this->assertSame('icorp', $configuration->refresh()->selected_source);
    }

    #[TestWith(['version'])]
    #[TestWith(['supplier'])]
    #[TestWith(['external_id'])]
    public function test_external_profile_rejects_changed_identity_or_version(string $change): void
    {
        [$configuration, $partner] = $this->configured();
        $data = $this->profile($partner);
        match ($change) {
            'version' => $partner->increment('profile_version'),
            'supplier' => $partner->update(['supplier_organization_id' => Organization::factory()->wholesaler()->create()->id]),
            'external_id' => $partner->update(['external_id' => 'changed']),
        };
        $this->actingAs($this->admin())->putJson(route('admin.supplier-import-profiles.external-profile', [$configuration->supplier, $partner]), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('expected_profile_version');
        $this->assertSame(1, $configuration->fresh()->version);
        $this->assertDatabaseMissing('audit_events', ['event' => 'supplier_sync.profile_updated']);
    }

    public function test_cross_supplier_partner_profile_cannot_be_changed(): void
    {
        [$configuration, $partner] = $this->configured();
        $other = Organization::factory()->wholesaler()->create();
        $data = $this->profile($partner);
        $data['expected_supplier_organization_id'] = $other->id;
        $this->actingAs($this->admin())->putJson(route('admin.supplier-import-profiles.external-profile', [$other, $partner]), $data)->assertUnprocessable();
        $this->assertSame(1, $partner->fresh()->profile_version);
    }

    public function test_failed_selected_source_does_not_attempt_another_enabled_source(): void
    {
        [$configuration] = $this->configured(['paused' => true]);
        ExternalSupplierPartner::factory()->create(['supplier_organization_id' => $configuration->supplier_organization_id, 'source' => 'pricelist_tj', 'profile_active' => true, 'profile_configuration' => ExternalPriceWorkbook::profile()]);
        $current = PriceListImport::factory()->for($configuration->supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $configuration->supplier->update(['active_price_list_import_id' => $current->id]);
        Http::preventStrayRequests();
        config(['external-prices.icorp.phone' => null]);
        $run = app(SupplierSynchronizationService::class)->request($configuration->supplier);
        app(SupplierSynchronizationService::class)->process($run);
        $this->assertSame(['icorp'], $run->attempts()->pluck('source')->all());
        $this->assertSame('failed', $run->attempts()->first()->status);
        $this->assertSame($current->id, $configuration->supplier->fresh()->active_price_list_import_id);
        Http::assertNothingSent();
    }

    public function test_nonselected_mail_is_excluded_even_with_unconfirmed_settings_and_manual_upload_remains_eligible(): void
    {
        [$configuration] = $this->configured(['settings_confirmed' => false]);
        $mail = PriceListImport::factory()->for($configuration->supplier, 'supplier')->create(['source_type' => 'email', 'status' => PriceListImportStatus::Committing]);
        $this->assertSame('source_not_selected', app(SupplierSourcePolicy::class)->reason($mail));
        try {
            app(ImportActivator::class)->commit($mail);
            $this->fail('Excluded mail must not activate.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('source_not_selected', $exception->getMessage());
        }
        $this->assertNull($configuration->supplier->fresh()->active_price_list_import_id);
        $mail->update(['source_type' => 'manual']);
        $this->assertNull(app(SupplierSourcePolicy::class)->reason($mail));
    }

    public function test_selected_email_manual_sync_waits_for_new_mail_without_replaying_history(): void
    {
        [$configuration] = $this->configured(['selected_source' => 'email', 'paused' => true]);
        SupplierImportProfile::factory()->for($configuration->supplier, 'supplier')->create();
        Http::preventStrayRequests();
        $run = app(SupplierSynchronizationService::class)->request($configuration->supplier);
        app(SupplierSynchronizationService::class)->process($run);
        $this->assertSame('no_new_mailbox_list', $run->fresh()->reason);
        $this->assertSame(['email'], $run->attempts()->pluck('source')->all());
        $this->assertDatabaseCount('price_list_imports', 0);
        Http::assertNothingSent();
    }

    public function test_unmapping_selected_source_allows_pause_but_blocks_manual_sync_without_switching(): void
    {
        [$configuration, $partner] = $this->configured(['automatic_enabled' => true]);
        $partner->update(['supplier_organization_id' => null]);
        $data = $this->settings(1);
        $data['paused'] = true;
        $data['automatic_enabled'] = true;
        $this->actingAs($this->admin())->putJson(route('admin.supplier-import-profiles.settings', $configuration->supplier), $data)->assertRedirect();
        $this->postJson(route('admin.supplier-sync.fetch', $configuration->supplier))->assertUnprocessable()->assertJsonValidationErrors('selected_source');
        $this->assertSame('icorp', $configuration->refresh()->selected_source);
        $this->assertTrue($configuration->fresh()->paused);
        $this->assertDatabaseCount('supplier_sync_runs', 0);
    }

    #[TestWith(['selection', 'source_not_selected'])]
    #[TestWith(['configuration', 'configuration_changed'])]
    #[TestWith(['mapping', 'mapping_changed'])]
    #[TestWith(['profile', 'profile_changed'])]
    public function test_final_cutover_rechecks_mutable_source_evidence(string $change, string $reason): void
    {
        [$configuration, $partner] = $this->configured(['automatic_enabled' => true]);
        $candidate = PriceListImport::factory()->for($configuration->supplier, 'supplier')->create([
            'source_type' => 'icorp', 'status' => PriceListImportStatus::Committing, 'source_published_at' => now()->subHour(), 'source_date_precision' => 'second',
            'source_metadata' => ['configuration_version' => 1, 'partner_id' => $partner->id, 'profile_version' => 1],
        ]);
        match ($change) {
            'selection' => $configuration->update(['selected_source' => 'email']),
            'configuration' => $configuration->increment('version'),
            'mapping' => $partner->update(['supplier_organization_id' => null]),
            'profile' => $partner->increment('profile_version'),
        };
        try {
            app(ImportActivator::class)->commit($candidate);
            $this->fail('Stale source evidence must block cutover.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
        $this->assertNull($configuration->supplier->fresh()->active_price_list_import_id);
    }

    public function test_conversion_dry_run_apply_rerun_and_rollback_preserve_catalogs_and_reference_settings(): void
    {
        [$configuration] = $this->configured(['selected_source' => null, 'settings_confirmed' => false, 'paused' => true, 'automatic_enabled' => true]);
        $priorities = $configuration->sources;
        $current = PriceListImport::factory()->for($configuration->supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $pending = PriceListImport::factory()->for($configuration->supplier, 'supplier')->create(['source_type' => 'icorp', 'status' => PriceListImportStatus::Pending]);
        $preview = PriceListImport::factory()->for($configuration->supplier, 'supplier')->create(['source_type' => 'icorp', 'status' => PriceListImportStatus::Preview]);
        $configuration->supplier->update(['active_price_list_import_id' => $current->id]);
        $this->artisan('suppliers:convert-sources')->expectsOutputToContain('selected=email; readiness=missing_profile; settings_confirmed=0; unfinished=2')->assertSuccessful();
        $this->assertNull($configuration->refresh()->selected_source);
        $this->assertSame(1, $configuration->version);
        $this->artisan('suppliers:convert-sources --apply')->assertFailed();
        $this->artisan('suppliers:convert-sources --pause')->assertSuccessful();
        $this->artisan('suppliers:convert-sources --apply')->assertSuccessful();
        $this->assertSame('email', $configuration->refresh()->selected_source);
        $this->assertSame(2, $configuration->version);
        $this->assertSame($priorities, $configuration->sources);
        $this->assertFalse($configuration->settings_confirmed);
        $this->assertTrue($configuration->automatic_enabled);
        $this->assertTrue($configuration->paused);
        $this->assertSame(PriceListImportStatus::Failed, $pending->fresh()->status);
        $this->assertSame(PriceListImportStatus::Preview, $preview->fresh()->status);
        $this->assertSame($current->id, $configuration->supplier->fresh()->active_price_list_import_id);
        $this->artisan('suppliers:convert-sources --apply')->assertSuccessful();
        $this->assertSame(2, $configuration->fresh()->version);
        $this->assertDatabaseCount('external_supplier_partners', 1);
        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_sync.source_converted']);
        $this->artisan('suppliers:convert-sources --rollback')->assertSuccessful();
        $this->assertNull($configuration->refresh()->selected_source);
        $this->assertSame(3, $configuration->version);
        $this->assertSame($priorities, $configuration->sources);
        $this->assertSame($current->id, $configuration->supplier->fresh()->active_price_list_import_id);
        $this->assertSame(PriceListImportStatus::Failed, $pending->fresh()->status);
    }

    #[TestWith(['duplicate'])]
    #[TestWith(['malformed'])]
    #[TestWith(['none'])]
    public function test_invalid_or_ambiguous_conversion_stays_unselected_and_blocked(string $failure): void
    {
        [$configuration] = $this->configured(['selected_source' => null]);
        $sources = $configuration->sources;
        if ($failure === 'duplicate') {
            $sources['icorp']['priority'] = 1;
        } elseif ($failure === 'malformed') {
            $sources['email']['priority'] = 'bad';
        } else {
            foreach ($sources as &$source) {
                $source['enabled'] = false;
            }
        }
        $configuration->update(['sources' => $sources]);
        $this->artisan('suppliers:convert-sources --pause')->assertSuccessful();
        $this->artisan('suppliers:convert-sources --apply')->assertSuccessful();
        $this->assertNull($configuration->refresh()->selected_source);
        $this->assertSame([], $configuration->enabledSources());
        $this->assertSame($sources, $configuration->sources);
        $this->artisan('suppliers:convert-sources --apply')->assertSuccessful();
        $this->assertSame(2, $configuration->fresh()->version);
    }

    public function test_unavailable_highest_rank_is_retained_instead_of_using_a_ready_lower_rank(): void
    {
        [$configuration] = $this->configured(['selected_source' => null]);
        $sources = $configuration->sources;
        $sources['pricelist_tj']['priority'] = 1;
        $sources['email']['priority'] = 3;
        $configuration->update(['sources' => $sources]);
        $this->artisan('suppliers:convert-sources --pause')->assertSuccessful();
        $this->artisan('suppliers:convert-sources --apply')->assertSuccessful();
        $this->assertSame('pricelist_tj', $configuration->refresh()->selected_source);
        $this->assertSame('unmapped', $configuration->source_conversion['reason']);
        $this->assertSame(['pricelist_tj'], $configuration->enabledSources());
    }

    public function test_conversion_refuses_active_work_and_rollback_refuses_intervening_edits(): void
    {
        [$configuration] = $this->configured(['selected_source' => null]);
        $processing = PriceListImport::factory()->for($configuration->supplier, 'supplier')->create(['status' => PriceListImportStatus::Processing]);
        $this->artisan('suppliers:convert-sources --pause')->assertSuccessful();
        $this->artisan('suppliers:convert-sources --apply')->assertFailed();
        $this->assertSame(1, $configuration->fresh()->version);
        $processing->update(['status' => PriceListImportStatus::Preview]);
        $this->artisan('suppliers:convert-sources --apply')->assertSuccessful();
        $configuration->refresh()->increment('version');
        $this->artisan('suppliers:convert-sources --rollback')->assertFailed();
        $this->assertSame('email', $configuration->refresh()->selected_source);
    }

    public function test_queued_manual_run_is_replaced_with_current_selection_after_configuration_changes(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        [$configuration] = $this->configured();
        $service = app(SupplierSynchronizationService::class);
        $run = $service->request($configuration->supplier);
        SupplierImportProfile::factory()->for($configuration->supplier, 'supplier')->create();
        $configuration->update(['selected_source' => 'email', 'version' => 2]);
        $service->process($run);
        $this->assertSame('configuration_changed', $run->fresh()->reason);
        $this->assertSame('email', SupplierSyncRun::whereNotNull('active_supplier_id')->firstOrFail()->configuration_snapshot['selected_source']);
        Queue::assertPushed(SynchronizeSupplier::class, 2);
    }

    public function test_mapping_exposes_a_source_without_selecting_it_or_allowing_unconfirmed_mail_activation(): void
    {
        config(['price-list-imports.exclusive_sources' => false]);
        $supplier = Organization::factory()->wholesaler()->create();
        $partner = ExternalSupplierPartner::factory()->create(['source' => 'icorp']);
        $this->actingAs($this->admin())->post(route('admin.supplier-sync.map', $partner), ['mapping_mode' => 'existing', 'supplier_organization_id' => $supplier->id, 'confirmed' => 1])->assertRedirect(route('admin.supplier-import-profiles.edit', $supplier));
        $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->firstOrFail();
        $this->assertNull($configuration->selected_source);
        $this->assertFalse($configuration->automatic_enabled);
        $this->assertFalse($configuration->settings_confirmed);
        $this->assertSame([], $configuration->enabledSources());
        $mail = PriceListImport::factory()->for($supplier, 'supplier')->create(['source_type' => 'email']);
        $this->assertSame('source_not_selected', app(SupplierSourcePolicy::class)->reason($mail));
        $this->get(route('admin.supplier-import-profiles.edit', $supplier))->assertSee($partner->name)->assertSee('expected_profile_version');
    }

    public function test_nonselected_mail_receipt_is_processed_without_activating_its_catalog(): void
    {
        Storage::fake('local');
        [$configuration] = $this->configured(['settings_confirmed' => false]);
        SupplierImportProfile::factory()->for($configuration->supplier, 'supplier')->create(['file_type' => 'csv']);
        SupplierSenderAddress::factory()->for($configuration->supplier, 'supplier')->create(['email' => 'supplier@example.com', 'normalized_email' => 'supplier@example.com']);
        $receipt = GmailImportReceipt::factory()->create(['supplier_organization_id' => $configuration->supplier_organization_id, 'sender_email' => 'supplier@example.com', 'received_at' => now(), 'selected_part' => ['file_type' => 'csv']]);
        Storage::disk('local')->put('mail.csv', "name,price\nАспирин,12.5\n");
        $file = new StoredImportFile('local', 'mail.csv', 'mail.csv', 'text/csv', Storage::disk('local')->size('mail.csv'), hash_file('sha256', Storage::disk('local')->path('mail.csv')));
        $result = app(SupplierPriceListIngestor::class)->ingestGmailReceipt($receipt, $file);
        $import = $result['import']->fresh();
        $this->assertSame('accepted', $receipt->fresh()->status);
        $this->assertSame(PriceListImportStatus::Preview, $import->status);
        $this->assertSame('source_not_selected', app(SupplierSourcePolicy::class)->reason($import));
        $this->assertNull($configuration->supplier->fresh()->active_price_list_import_id);
        $this->assertDatabaseHas('supplier_sync_attempts', ['price_list_import_id' => $import->id, 'reason' => 'source_not_selected']);
        $this->actingAs($this->admin())->get(route('price-list-imports.show', $import))->assertSee('Источник исключён');
    }

    public function test_cutover_pause_preserves_pending_receipts_without_consuming_attempts(): void
    {
        config(['gmail-imports.enabled' => true]);
        Cache::forever('supplier-imports:dispatch-paused', true);
        $receipt = GmailImportReceipt::factory()->create();
        Http::preventStrayRequests();
        app()->call([new ProcessGmailImportReceipt($receipt->id), 'handle']);
        app()->call([new SyncGmailImports, 'handle']);
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertSame(0, $receipt->attempts);
        Http::assertNothingSent();
    }

    public function test_recovery_replaces_old_external_evidence_with_work_for_the_current_selection(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        [$configuration] = $this->configured(['selected_source' => 'email', 'automatic_enabled' => true, 'version' => 2]);
        SupplierImportProfile::factory()->for($configuration->supplier, 'supplier')->create();
        $old = PriceListImport::factory()->for($configuration->supplier, 'supplier')->create(['source_type' => 'icorp', 'status' => PriceListImportStatus::Processing,
            'source_metadata' => ['configuration_version' => 1, 'trigger' => 'manual'], 'updated_at' => now()->subHour()]);
        $this->artisan('suppliers:sync')->assertSuccessful();
        $this->assertSame(PriceListImportStatus::Failed, $old->fresh()->status);
        $this->assertSame('source_configuration_changed', $old->refresh()->failure_stage);
        $run = SupplierSyncRun::whereNotNull('active_supplier_id')->firstOrFail();
        $this->assertSame('email', $run->configuration_snapshot['selected_source']);
        $this->assertSame(2, $run->configuration_snapshot['version']);
        $this->assertSame('manual', $run->trigger);
        Queue::assertPushed(SynchronizeSupplier::class);
    }

    public function test_malformed_converted_configuration_can_be_repaired_without_an_email_sample(): void
    {
        [$configuration] = $this->configured(['selected_source' => null, 'sources' => 'malformed']);
        $this->artisan('suppliers:convert-sources --pause')->assertSuccessful();
        $this->artisan('suppliers:convert-sources --apply')->assertSuccessful();
        $this->assertSame('invalid_ranking', $configuration->refresh()->source_conversion['reason']);
        $data = $this->settings(2);
        $data['selected_source'] = 'email';
        $this->actingAs($this->admin())->get(route('admin.supplier-import-profiles.edit', $configuration->supplier))->assertOk();
        $this->putJson(route('admin.supplier-import-profiles.settings', $configuration->supplier), $data)->assertRedirect();
        $this->assertSame('email', $configuration->refresh()->selected_source);
        $this->assertSame(3, $configuration->version);
        $this->assertDatabaseMissing('supplier_import_profiles', ['supplier_organization_id' => $configuration->supplier_organization_id]);
    }

    #[TestWith(['icorp', 'iCorp'])]
    #[TestWith(['pricelist_tj', 'PriceList.tj'])]
    public function test_profile_runs_saved_external_source_without_saving_forms(string $source, string $label): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        [$configuration, $partner] = $this->configured(['selected_source' => $source, 'paused' => true]);
        $partner->update(['source' => $source]);
        config(['external-prices.'.$source.'.phone' => '123', 'external-prices.'.$source.'.password' => 'test', 'external-prices.'.$source.'.firm_id' => '123']);
        $url = route('admin.supplier-import-profiles.edit', $configuration->supplier);
        $this->actingAs($this->admin())->get($url)->assertSee('Импортировать из '.$label)->assertSee('name="expected_version"', false);
        $before = $configuration->getAttributes();
        $profileBefore = $partner->fresh()->toArray();
        $draft = ['message' => 'kept'];
        $returnUrl = $url.'?gmail_draft=kept';
        $payload = ['_sync_form' => 'run-'.$configuration->supplier_organization_id, 'expected_version' => 1, 'expected_source' => $source];
        $this->withSession(['gmail_profile_drafts.keep' => $draft])->from($returnUrl)->post(route('admin.supplier-sync.fetch', $configuration->supplier), $payload)
            ->assertRedirect($returnUrl)->assertSessionHasNoErrors()->assertSessionHas('gmail_profile_drafts.keep', $draft);
        $this->post(route('admin.supplier-sync.fetch', $configuration->supplier), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, SupplierSyncRun::count());
        $this->assertSame($source, SupplierSyncRun::firstOrFail()->configuration_snapshot['selected_source']);
        $this->assertSame($before, $configuration->fresh()->getAttributes());
        $this->assertSame($profileBefore, $partner->fresh()->toArray());
        $this->get($url)->assertSee('История и результат импорта');
        Queue::assertPushed(SynchronizeSupplier::class, 2);
    }

    #[TestWith(['version'])]
    #[TestWith(['source'])]
    #[TestWith(['profile'])]
    #[TestWith(['credentials'])]
    #[TestWith(['inactive'])]
    public function test_profile_run_rejects_stale_or_unavailable_source(string $failure): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        [$configuration, $partner] = $this->configured();
        config(['external-prices.icorp.phone' => '123', 'external-prices.icorp.password' => 'test', 'external-prices.icorp.firm_id' => '123']);
        match ($failure) {
            'version' => $configuration->increment('version'),
            'source' => $configuration->update(['selected_source' => 'email']),
            'profile' => $partner->update(['profile_active' => false]),
            'credentials' => config(['external-prices.icorp.password' => null]),
            'inactive' => $configuration->supplier->update(['status' => 'pending']),
        };
        $this->actingAs($this->admin())->postJson(route('admin.supplier-sync.fetch', $configuration->supplier), ['expected_version' => 1, 'expected_source' => 'icorp'])
            ->assertUnprocessable()->assertJsonValidationErrors(in_array($failure, ['version', 'source'], true) ? 'expected_version' : 'expected_source');
        $this->assertDatabaseCount('supplier_sync_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_non_administrator_cannot_run_an_import_from_profile(): void
    {
        [$configuration] = $this->configured();
        $this->actingAs(User::factory()->create())->post(route('admin.supplier-sync.fetch', $configuration->supplier), ['expected_version' => 1, 'expected_source' => 'icorp'])->assertForbidden();
        $this->assertDatabaseCount('supplier_sync_runs', 0);
    }

    public function test_profile_run_tokens_are_paired_and_email_has_no_external_run_action(): void
    {
        [$configuration] = $this->configured(['selected_source' => 'email']);
        $this->actingAs($this->admin())->get(route('admin.supplier-import-profiles.edit', $configuration->supplier))
            ->assertDontSee('Импортировать из')->assertSee('Для импорта выберите этот источник');
        $this->postJson(route('admin.supplier-sync.fetch', $configuration->supplier), ['expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('expected_source');
        $this->postJson(route('admin.supplier-sync.fetch', $configuration->supplier), ['expected_source' => 'icorp'])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->assertDatabaseCount('supplier_sync_runs', 0);
    }

    /** @return array{SupplierSynchronization, ExternalSupplierPartner} */
    private function configured(array $attributes = []): array
    {
        $configuration = SupplierSynchronization::factory()->create(array_replace(['selected_source' => 'icorp', 'settings_confirmed' => true], $attributes));
        $partner = ExternalSupplierPartner::factory()->create(['supplier_organization_id' => $configuration->supplier_organization_id, 'source' => 'icorp', 'profile_active' => true, 'profile_configuration' => ExternalPriceWorkbook::profile()]);

        return [$configuration->refresh(), $partner->refresh()];
    }

    private function settings(int $version = 0): array
    {
        return ['expected_version' => $version, 'selected_source' => 'icorp', 'automatic_enabled' => false, 'paused' => false, 'fetch_interval_minutes' => 15, 'freshness_hours' => 24,
            'sources' => ['email' => ['priority' => 1], 'icorp' => ['priority' => 2], 'pricelist_tj' => ['priority' => 3]]];
    }

    private function profile(ExternalSupplierPartner $partner): array
    {
        return ['expected_supplier_organization_id' => $partner->supplier_organization_id, 'expected_profile_version' => $partner->profile_version,
            'expected_source' => $partner->source, 'expected_external_id' => $partner->external_id,
            'profile_active' => true, 'matching_strategy' => 'sku', 'date_formats' => 'd.m.Y, Y-m-d', 'minimum_valid_rows' => 1, 'maximum_error_rows' => 0, 'maximum_error_percentage' => 0];
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
    }
}
