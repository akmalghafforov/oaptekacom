<?php

namespace Tests\Feature;

use App\DataTransferObjects\StoredImportFile;
use App\Enums\PriceListImportStatus;
use App\Jobs\CommitPriceListImport;
use App\Jobs\SynchronizeSupplier;
use App\Models\AuditEvent;
use App\Models\ExternalSupplierPartner;
use App\Models\GmailImportReceipt;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierImportProfile;
use App\Models\SupplierSenderAddress;
use App\Models\SupplierSyncAttempt;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use App\Models\User;
use App\Services\ExternalPriceWorkbook;
use App\Services\ExternalSupplierDirectory;
use App\Services\PriceList\ImportActivator;
use App\Services\PriceList\ProfileValidator;
use App\Services\SupplierPriceListIngestor;
use App\Services\SupplierSourcePolicy;
use App\Services\SupplierSynchronizationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupplierSynchronizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_administrator_sees_searchable_mapping_evidence_without_automatic_merging(): void
    {
        Organization::factory()->wholesaler()->create(['name' => 'Осон-Фарм']);
        Organization::factory()->wholesaler()->create(['name' => 'Другая фирма', 'phone' => '+992927430570']);
        $partner = $this->partner(['name' => 'ОСОН ФАРМ', 'phones' => ['927430570']]);

        $this->actingAs($this->admin())->get(route('admin.supplier-sync.index', ['filter' => 'ambiguous']))
            ->assertOk()->assertSee('Совпадает название')->assertSee('Совпадает телефон')->assertSee('ОСОН ФАРМ');
        $this->assertNull($partner->fresh()->supplier_organization_id);
    }

    public function test_non_administrator_cannot_read_or_change_mappings(): void
    {
        $partner = $this->partner();
        $this->actingAs(User::factory()->create())
            ->get(route('admin.supplier-sync.index'))->assertForbidden();
        $this->post(route('admin.supplier-sync.map', $partner), ['name' => 'Новая', 'confirmed' => 1])->assertForbidden();
        $this->assertNull($partner->fresh()->supplier_organization_id);
    }

    public function test_mapping_can_create_an_active_supplier_and_starts_with_automatic_fetching_disabled(): void
    {
        $partner = $this->partner();
        $this->actingAs($this->admin())->post(route('admin.supplier-sync.map', $partner), ['name' => 'Новый поставщик', 'confirmed' => 1])->assertRedirect();

        $supplier = $partner->fresh()->supplier;
        $this->assertSame('active', $supplier->status);
        $this->assertFalse(SupplierSynchronization::where('supplier_organization_id', $supplier->id)->firstOrFail()->automatic_enabled);
        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_mapping.confirmed', 'subject_id' => $partner->id]);
        $this->get(route('admin.supplier-sync.show', $supplier))->assertOk()->assertSee('История синхронизации');
    }

    public function test_mapping_conflict_is_rejected_and_confirmed_mapping_is_preserved(): void
    {
        [$config, $partner] = $this->configured();
        $other = $this->partner(['external_id' => 'other']);
        $this->actingAs($this->admin())->postJson(route('admin.supplier-sync.map', $other), ['supplier_organization_id' => $config->supplier_organization_id, 'confirmed' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_organization_id');
        $this->assertNull($other->fresh()->supplier_organization_id);
        $this->assertSame($config->supplier_organization_id, $partner->fresh()->supplier_organization_id);
    }

    public function test_enabling_automatic_fetching_requires_active_profiles_and_unique_priorities(): void
    {
        [$config, $partner] = $this->configured();
        $partner->update(['profile_active' => false]);
        $data = $config->only(['automatic_enabled', 'paused', 'fetch_interval_minutes', 'freshness_hours', 'sources']) + ['expected_version' => $config->version, 'selected_source' => 'pricelist_tj'];
        $data['automatic_enabled'] = true;
        $this->actingAs($this->admin())->putJson(route('admin.supplier-sync.update', $config->supplier), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('selected_source');
        $data['sources']['icorp']['priority'] = 3;
        $this->putJson(route('admin.supplier-sync.update', $config->supplier), $data)->assertUnprocessable();
        $this->assertSame(1, $config->fresh()->version);
    }

    public function test_manual_fetch_works_while_disabled_and_paused_and_imports_displayed_prices(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        [$config] = $this->configured(['automatic_enabled' => false, 'paused' => true]);
        $this->fakePrices(now()->subHour()->format('Y-m-d\TH:i:sP'));

        $run = app(SupplierSynchronizationService::class)->request($config->supplier);
        app(SupplierSynchronizationService::class)->process($run->fresh());

        $import = PriceListImport::firstOrFail();
        $this->assertSame(PriceListImportStatus::Completed, $import->status);
        $this->assertSame('9.00', $import->offers()->firstOrFail()->price);
        $this->assertSame(10, $import->source_metadata['account_discount']);
        $this->assertNull($import->supplier_import_profile_id);
        $this->assertNotNull($config->fresh()->last_activated_at);
        $this->assertSame('completed', $run->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_automatic_queued_run_does_not_fetch_after_pause(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        Http::preventStrayRequests();
        [$config] = $this->configured();
        $run = app(SupplierSynchronizationService::class)->request($config->supplier, 'automatic');
        $config->update(['paused' => true]);

        app(SupplierSynchronizationService::class)->process($run);

        $this->assertSame('paused', $run->fresh()->reason);
        $this->assertNull($run->fresh()->active_supplier_id);
        Http::assertNothingSent();
        Queue::assertPushed(SynchronizeSupplier::class);
    }

    public function test_missing_date_retains_review_preview_then_audited_confirmation_activates_it(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        [$config] = $this->configured();
        $this->fakePrices(null);
        $run = app(SupplierSynchronizationService::class)->request($config->supplier);
        app(SupplierSynchronizationService::class)->process($run->fresh());
        $import = PriceListImport::firstOrFail();
        $this->assertSame(PriceListImportStatus::Preview, $import->status);
        $this->assertNull($config->supplier->fresh()->active_price_list_import_id);
        $this->assertSame('review_missing_date', $run->fresh()->reason);

        $this->actingAs($this->admin())->post(route('admin.supplier-sync.date', $import), [
            'published_at' => now()->subHour()->format('Y-m-d\TH:i:s'), 'evidence' => 'Подтверждено поставщиком',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(PriceListImportStatus::Completed, $import->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_sync.date_confirmed', 'subject_id' => $import->id]);
        Http::assertSentCount(2);
    }

    public function test_validation_failure_does_not_replace_existing_catalog(): void
    {
        Storage::fake('local');
        [$config, $partner] = $this->configured();
        $current = PriceListImport::factory()->for($config->supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $config->supplier->update(['active_price_list_import_id' => $current->id]);
        $partner->update(['profile_configuration' => ExternalPriceWorkbook::profile(['automatic' => ['minimum_valid_rows' => 10]])]);
        $this->fakePrices(now()->format('Y-m-d\TH:i:sP'));

        $run = app(SupplierSynchronizationService::class)->request($config->supplier);
        app(SupplierSynchronizationService::class)->process($run->fresh());

        $this->assertSame($current->id, $config->supplier->fresh()->active_price_list_import_id);
        $this->assertSame('validation_failed', $run->attempts()->firstOrFail()->reason);
        Http::assertSentCount(2);
    }

    public function test_fresh_preferred_mailbox_blocks_external_fallback_without_renewing_its_date(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        [$config] = $this->configured();
        $config->update(['sources' => SupplierSynchronization::defaultSources()]);
        $date = now()->subHours(2)->startOfSecond();
        $mail = PriceListImport::factory()->for($config->supplier, 'supplier')->create([
            'source_type' => 'email', 'status' => PriceListImportStatus::Completed, 'source_published_at' => $date,
            'valid_rows' => 1, 'error_rows' => 0, 'total_rows' => 1,
        ]);

        $run = app(SupplierSynchronizationService::class)->request($config->supplier);
        app(SupplierSynchronizationService::class)->process($run->fresh());

        $this->assertSame('preferred_fresh', $run->fresh()->reason);
        $this->assertTrue($mail->fresh()->source_published_at->equalTo($date));
        Http::assertNothingSent();
    }

    public function test_stale_preferred_source_allows_external_fallback(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        [$config] = $this->configured();
        $sources = $config->sources;
        $sources['email']['enabled'] = true;
        $config->update(['sources' => $sources]);
        $mail = PriceListImport::factory()->for($config->supplier, 'supplier')->create([
            'source_type' => 'email', 'status' => PriceListImportStatus::Completed, 'source_published_at' => now()->subHours(25),
            'source_date_precision' => 'second', 'valid_rows' => 1, 'error_rows' => 0, 'total_rows' => 1,
        ]);
        $config->supplier->update(['active_price_list_import_id' => $mail->id]);
        $this->fakePrices(now()->subHour()->format('Y-m-d\TH:i:sP'));

        $run = app(SupplierSynchronizationService::class)->request($config->supplier);
        app(SupplierSynchronizationService::class)->process($run->fresh());

        $this->assertNotSame($mail->id, $config->supplier->fresh()->active_price_list_import_id);
        $this->assertSame('completed', $run->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_repeated_content_in_different_order_is_skipped_without_refreshing_publication_date(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        [$config] = $this->configured();
        $items = [$this->item('one'), $this->item('two')];
        $this->fakePrices(now()->subHour()->format('Y-m-d\TH:i:sP'), $items);
        $service = app(SupplierSynchronizationService::class);
        $run = $service->request($config->supplier);
        $service->process($run->fresh());
        $date = PriceListImport::firstOrFail()->source_published_at;
        $this->fakePrices(now()->format('Y-m-d\TH:i:sP'), array_reverse($items));

        $second = $service->request($config->supplier);
        $service->process($second->fresh());

        $this->assertSame(1, PriceListImport::count());
        $this->assertSame('unchanged_content', $second->attempts()->firstOrFail()->reason);
        $this->assertTrue(PriceListImport::first()->source_published_at->equalTo($date));
        Http::assertSentCount(1);
    }

    public function test_duplicate_fetch_requests_coalesce_and_abandoned_run_can_be_recovered(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        Storage::fake('local');
        [$config] = $this->configured();
        $this->fakePrices(now()->format('Y-m-d\TH:i:sP'));
        $service = app(SupplierSynchronizationService::class);
        $first = $service->request($config->supplier);
        $second = $service->request($config->supplier);
        $this->assertSame($first->id, $second->id);
        $first->update(['owner_token' => 'abandoned', 'lease_expires_at' => now()->subMinute()]);

        $service->process($first->fresh());

        $this->assertSame(1, SupplierSyncRun::count());
        $this->assertSame(PriceListImportStatus::Completed, PriceListImport::firstOrFail()->status);
        Queue::assertPushed(SynchronizeSupplier::class, 2);
        Http::assertSentCount(2);
    }

    #[TestWith(['paused', 'paused'])]
    #[TestWith(['version', 'configuration_changed'])]
    #[TestWith(['mapping', 'mapping_changed'])]
    #[TestWith(['profile', 'profile_changed'])]
    #[TestWith(['disabled', 'source_disabled'])]
    public function test_activation_rechecks_current_configuration(string $change, string $reason): void
    {
        $this->freezeTime();
        [$config, $partner] = $this->configured();
        $candidate = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Committing]);
        match ($change) {
            'paused' => $config->update(['paused' => true]),
            'version' => $config->increment('version'),
            'mapping' => $partner->update(['supplier_organization_id' => null]),
            'profile' => $partner->increment('profile_version'),
            'disabled' => $config->update(['sources' => array_replace($config->sources, ['pricelist_tj' => ['enabled' => false, 'priority' => 3]])]),
        };

        try {
            app(ImportActivator::class)->commit($candidate);
            $this->fail('Activation must reject changed source configuration.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage());
        }
        $this->assertNull($config->supplier->fresh()->active_price_list_import_id);
    }

    public function test_new_mailbox_import_prevents_queued_external_cutover(): void
    {
        $this->freezeTime();
        [$config, $partner] = $this->configured();
        $config->update(['sources' => SupplierSynchronization::defaultSources()]);
        $candidate = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Committing]);
        PriceListImport::factory()->for($config->supplier, 'supplier')->create(['source_type' => 'email', 'status' => PriceListImportStatus::Processing]);

        $this->assertSame('preferred_unresolved', app(SupplierSourcePolicy::class)->reason($candidate));
        $mail = PriceListImport::where('source_type', 'email')->firstOrFail();
        $mail->update(['status' => PriceListImportStatus::Completed, 'source_published_at' => now(), 'valid_rows' => 1, 'error_rows' => 0]);
        $config->supplier->update(['active_price_list_import_id' => $mail->id]);
        $this->assertSame('preferred_fresh', app(SupplierSourcePolicy::class)->reason($candidate));
    }

    public function test_date_precision_requires_review_and_same_source_equal_date_allows_changed_content(): void
    {
        $this->freezeTime();
        [$config, $partner] = $this->configured();
        $current = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Completed, 'source_published_at' => now()->startOfDay(), 'source_date_precision' => 'day']);
        $config->supplier->update(['active_price_list_import_id' => $current->id]);
        $candidate = $this->candidate($config, $partner, ['source_published_at' => now()->startOfDay()->addMinute()]);
        $this->assertSame('review_date_precision', app(SupplierSourcePolicy::class)->reason($candidate));
        $candidate->update(['source_published_at' => $current->source_published_at, 'source_date_precision' => 'day']);
        $this->assertNull(app(SupplierSourcePolicy::class)->reason($candidate));
    }

    public function test_failed_discovery_preserves_directory_and_confirmed_mapping(): void
    {
        Storage::fake('local');
        [$config, $partner] = $this->configured();
        config(['external-prices.pricelist_tj.phone' => '927430570', 'external-prices.pricelist_tj.password' => 'test']);
        Http::preventStrayRequests();
        Http::fake([
            'pricelist-tj.com/api/auth/login' => Http::response(['token' => 'secret-token']),
            'pricelist-tj.com/api/companies?*' => Http::response(['companies' => [['id' => 'supplier', 'name' => 'Обновлён', 'verified' => true]], 'pagination' => ['page' => 1, 'total' => 2, 'totalPages' => 1]]),
        ]);
        try {
            app(ExternalSupplierDirectory::class)->refresh('pricelist_tj');
            $this->fail('Truncated directory must fail.');
        } catch (\RuntimeException) {
            $this->assertSame('Поставщик', $partner->fresh()->name);
            $this->assertSame($config->supplier_organization_id, $partner->fresh()->supplier_organization_id);
        }
        Http::assertSentCount(2);
    }

    public function test_guest_cannot_access_supplier_synchronization(): void
    {
        $this->get(route('admin.supplier-sync.index'))->assertRedirect(route('login'));
    }

    public function test_settings_can_pause_resume_and_change_priorities_with_audit_history(): void
    {
        [$config] = $this->configured();
        $data = $config->only(['automatic_enabled', 'paused', 'fetch_interval_minutes', 'freshness_hours', 'sources']) + ['expected_version' => $config->version, 'selected_source' => 'pricelist_tj'];
        $data['paused'] = true;
        $data['sources']['email']['priority'] = 3;
        $data['sources']['pricelist_tj']['priority'] = 1;
        $this->actingAs($this->admin())->put(route('admin.supplier-sync.update', $config->supplier), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($config->fresh()->paused);
        $this->assertSame(['pricelist_tj'], $config->fresh()->enabledSources());
        $data['paused'] = false;
        $data['expected_version'] = $config->fresh()->version;

        $this->put(route('admin.supplier-sync.update', $config->supplier), $data)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($config->fresh()->paused);
        $this->assertSame(3, $config->fresh()->version);
        $this->assertSame(2, AuditEvent::where('event', 'supplier_sync.configured')->count());
    }

    public function test_manual_fetch_endpoint_coalesces_queued_requests(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        [$config] = $this->configured(['automatic_enabled' => false, 'paused' => true]);
        $this->actingAs($this->admin())->post(route('admin.supplier-sync.fetch', $config->supplier))->assertRedirect();
        $this->post(route('admin.supplier-sync.fetch', $config->supplier))->assertRedirect();

        $this->assertSame(1, SupplierSyncRun::count());
        $this->assertSame('manual', SupplierSyncRun::firstOrFail()->trigger);
        Queue::assertPushed(SynchronizeSupplier::class, 2);
    }

    public function test_scheduled_due_supplier_imports_and_future_checks_are_not_fetched(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        [$config] = $this->configured(['next_check_at' => now()->subMinute()]);
        $this->fakePrices(now()->subHour()->format('Y-m-d\TH:i:sP'));

        $this->artisan('suppliers:sync')->assertSuccessful();
        $this->artisan('suppliers:sync')->assertSuccessful();

        $this->assertSame(PriceListImportStatus::Completed, PriceListImport::firstOrFail()->status);
        $this->assertSame(1, SupplierSyncRun::count());
        $this->assertTrue($config->fresh()->next_check_at->isFuture());
        Http::assertSentCount(2);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_before_cutover_extraction_or_validation_failure_continues_to_next_source(bool $validationFailure): void
    {
        $this->freezeTime();
        Storage::fake('local');
        [$config] = $this->configured();
        $sources = $config->sources;
        $sources['icorp']['enabled'] = true;
        $config->update(['sources' => $sources]);
        $icorpPartner = $this->partner(['source' => 'icorp', 'external_id' => '156', 'supplier_organization_id' => $config->supplier_organization_id, 'metadata' => ['city' => 'Душанбе'],
            'profile_active' => true, 'profile_configuration' => ExternalPriceWorkbook::profile(['automatic' => ['minimum_valid_rows' => 5]])]);
        config(['external-prices.icorp.phone' => '927430570']);
        Storage::disk('local')->put('external-prices/sessions/icorp-'.hash('sha256', '927430570').'.encrypted', Crypt::encryptString(json_encode(['cookies' => [], 'user_agent' => 'test'])));
        $this->fakePrices(now()->subHour()->format('Y-m-d\TH:i:sP'));
        Http::fake([
            'icorp.tech/listpartners' => Http::response('<article data-partner-id="156" data-price-date="'.now()->subHour()->format('d.m.Y H:i:s').'"><h3>Фирма</h3><a href="/priceview/catalog">Прайс</a></article>'),
            'icorp.tech/productsFirmByOverhead?*' => $validationFailure ? Http::response('<div class="catalog-summary">Показано 1-1 из 1</div><table><tr class="catalog-cart-source"><td class="catalog-title">Товар</td><td class="catalog-price-main">12.50</td><td class="catalog-discount-badge">0%</td><td class="catalog-col-expire">-</td><td><input class="catalog-prid" value="one"><input class="catalog-over" value="catalog"><input class="catalog-firm" value="MTU2"></td></tr></table>') : Http::response('Unavailable', 503),
        ]);

        $run = app(SupplierSynchronizationService::class)->request($config->supplier);
        app(SupplierSynchronizationService::class)->process($run->fresh());

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('pricelist_tj', $run->fresh()->selected_source);
        $this->assertSame($validationFailure ? 'validation_failed' : 'transport', $run->attempts()->where('source', 'icorp')->firstOrFail()->reason);
        $this->assertSame('Душанбе', $icorpPartner->fresh()->metadata['city']);
        Http::assertSentCount(4);
    }

    public function test_configured_mailbox_receipt_uses_its_own_profile_and_records_sync_history(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        [$config] = $this->configured();
        $config->update(['sources' => array_replace($config->sources, ['email' => ['enabled' => true, 'priority' => 1]])]);
        $profile = SupplierImportProfile::factory()->for($config->supplier, 'supplier')->create(['file_type' => 'csv',
            'configuration' => ProfileValidator::defaults()]);
        SupplierSenderAddress::factory()->for($config->supplier, 'supplier')->create(['email' => 'supplier@example.com', 'normalized_email' => 'supplier@example.com']);
        Storage::disk('local')->put('mail.csv', "name,price\nАспирин,12.5\n");
        $receipt = GmailImportReceipt::factory()->create(['supplier_organization_id' => $config->supplier_organization_id,
            'sender_email' => 'supplier@example.com', 'received_at' => now()->subHour(), 'selected_part' => ['file_type' => 'csv']]);
        $file = new StoredImportFile('local', 'mail.csv', 'mail.csv', 'text/csv', Storage::disk('local')->size('mail.csv'), hash_file('sha256', Storage::disk('local')->path('mail.csv')));

        $result = app(SupplierPriceListIngestor::class)->ingestGmailReceipt($receipt, $file);

        $import = $result['import']->fresh();
        $this->assertSame(PriceListImportStatus::Completed, $import->status);
        $this->assertSame($profile->id, $import->supplier_import_profile_id);
        $this->assertTrue($import->source_published_at->equalTo($receipt->received_at));
        $this->assertDatabaseHas('supplier_sync_runs', ['supplier_organization_id' => $config->supplier_organization_id, 'trigger' => 'mailbox', 'status' => 'completed']);
    }

    public function test_missing_active_source_profile_prevents_external_requests(): void
    {
        Http::preventStrayRequests();
        [$config, $partner] = $this->configured();
        $partner->update(['profile_active' => false]);

        $run = app(SupplierSynchronizationService::class)->request($config->supplier);
        app(SupplierSynchronizationService::class)->process($run->fresh());

        $this->assertSame('missing_profile', $run->attempts()->firstOrFail()->reason);
        $this->assertSame(0, PriceListImport::count());
        Http::assertNothingSent();
    }

    public function test_connection_retry_cannot_request_takeover_and_explicit_takeover_requires_confirmation(): void
    {
        Http::preventStrayRequests();
        $this->actingAs($this->admin())->postJson(route('admin.supplier-sync.connect', 'icorp'), ['take_over' => 1])->assertUnprocessable();
        $this->postJson(route('admin.supplier-sync.takeover'))->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->assertSame(0, AuditEvent::where('event', 'supplier_sync.takeover_requested')->count());
        Http::assertNothingSent();
    }

    public function test_deterministic_workbook_preserves_formula_like_values_as_strings(): void
    {
        Storage::fake('local');
        $workbook = app(ExternalPriceWorkbook::class);
        $items = [['external_id' => '2', 'name' => '=1+1', 'price' => '9.00'], ['external_id' => '1', 'name' => '00123', 'price' => '12.50']];
        $first = $workbook->store($items);
        $this->travel(2)->hours();
        $second = $workbook->store(array_reverse($items));

        $this->assertSame($first->sha256, $second->sha256);
        $book = IOFactory::load(Storage::disk('local')->path($first->path));
        $this->assertSame('s', $book->getActiveSheet()->getCell('A3')->getDataType());
        $this->assertSame('=1+1', $book->getActiveSheet()->getCell('A3')->getValue());
        $this->assertSame('00123', $book->getActiveSheet()->getCell('A2')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_manual_request_resumes_a_queued_automatic_run_while_paused(): void
    {
        Storage::fake('local');
        Queue::fake([SynchronizeSupplier::class]);
        [$config] = $this->configured();
        $this->fakePrices(now()->subHour()->format('Y-m-d\TH:i:sP'));
        $service = app(SupplierSynchronizationService::class);
        $automatic = $service->request($config->supplier, 'automatic');
        $config->update(['paused' => true]);

        $manual = $service->request($config->supplier);
        $service->process($manual);

        $this->assertSame($automatic->id, $manual->id);
        $this->assertSame('completed', $manual->fresh()->status);
        $this->assertSame(PriceListImportStatus::Completed, PriceListImport::firstOrFail()->status);
        Queue::assertPushed(SynchronizeSupplier::class, 2);
        Http::assertSentCount(2);
    }

    public function test_lower_priority_mailbox_waits_for_an_external_attempt_before_import_exists(): void
    {
        [$config] = $this->configured();
        $sources = SupplierSynchronization::defaultSources();
        $sources['icorp']['priority'] = 1;
        $sources['email']['priority'] = 2;
        $config->update(['sources' => $sources]);
        $run = SupplierSyncRun::factory()->create(['supplier_organization_id' => $config->supplier_organization_id, 'active_supplier_id' => $config->supplier_organization_id]);
        $run->attempts()->create(['source' => 'icorp', 'status' => 'fetching']);

        $this->assertTrue(app(SupplierSourcePolicy::class)->unresolved($config->supplier_organization_id, ['icorp']));
        $run->attempts()->where('source', 'icorp')->update(['status' => 'failed']);
        $this->assertFalse(app(SupplierSourcePolicy::class)->unresolved($config->supplier_organization_id, ['icorp']));
    }

    public function test_date_only_publication_overlapping_the_freshness_boundary_requires_review(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Asia/Dushanbe'));
        [$config, $partner] = $this->configured();
        $candidate = $this->candidate($config, $partner, ['source_published_at' => '2026-11-10 00:00:00', 'source_date_precision' => 'day']);

        $this->assertSame('review_date_precision', app(SupplierSourcePolicy::class)->reason($candidate));
    }

    public function test_utc_publication_date_preserves_its_instant_when_saved_in_local_timezone(): void
    {
        $publication = ExternalPriceWorkbook::publicationDate('2026-11-11T06:00:00Z');

        $this->assertSame('2026-11-11 11:00:00', $publication['date']->format('Y-m-d H:i:s'));
        $this->assertSame('second', $publication['precision']);
        $this->assertNull(ExternalPriceWorkbook::publicationDate('31.02.2026')['date']);
        $this->assertNull(ExternalPriceWorkbook::publicationDate('yesterday')['date']);
    }

    public function test_external_workbook_cannot_be_replayed_with_mailbox_column_mappings(): void
    {
        [$config, $partner] = $this->configured();
        $import = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Completed]);
        $this->actingAs($this->admin())->post(route('price-list-imports.replay', $import))->assertUnprocessable();
        $this->assertSame(1, PriceListImport::count());
    }

    public function test_explicit_takeover_is_audited_and_uses_forced_connection_only_after_confirmation(): void
    {
        Storage::fake('local');
        config(['external-prices.icorp.phone' => '927430570', 'external-prices.icorp.password' => 'private-password', 'external-prices.icorp.firm_id' => '303']);
        Http::preventStrayRequests();
        Http::fake([
            'icorp.tech/login' => Http::response('', 302, ['Location' => '/choose']),
            'icorp.tech/choose' => Http::response('<meta name="csrf-token" content="private-csrf"><button data-firm-id="303">Вход</button>'),
            'icorp.tech/firm-entry' => Http::response(['success' => true]),
        ]);

        $this->actingAs($this->admin())->post(route('admin.supplier-sync.takeover'), ['confirmed' => 1])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_sync.takeover_requested']);
        $this->assertStringNotContainsString('private-password', AuditEvent::latest('id')->firstOrFail()->toJson());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://icorp.tech/firm-entry' && $request['force_firm_session'] === 1);
    }

    public function test_rejected_date_review_releases_the_run_for_a_new_attempt(): void
    {
        Storage::fake('local');
        [$config] = $this->configured();
        $this->fakePrices(null);
        $service = app(SupplierSynchronizationService::class);
        $run = $service->request($config->supplier);
        $service->process($run->fresh());
        $import = PriceListImport::firstOrFail();
        $this->actingAs($this->admin())->get(route('admin.supplier-sync.show', [$config->supplier, 'tab' => 'review']))->assertOk()->assertSee('Подтвердить дату');

        $this->post(route('admin.supplier-sync.reject', $import))->assertRedirect();
        $service->process($run->fresh());

        $this->assertSame(PriceListImportStatus::Failed, $import->fresh()->status);
        $this->assertNull($run->fresh()->active_supplier_id);
        $this->assertNull($config->supplier->fresh()->active_price_list_import_id);
        Http::assertSentCount(2);
    }

    public function test_cutover_policy_change_restores_reviewable_preview_and_preserves_active_catalog(): void
    {
        [$config, $partner] = $this->configured();
        $candidate = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Committing]);
        $current = PriceListImport::factory()->for($config->supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $config->supplier->update(['active_price_list_import_id' => $current->id]);
        $config->update(['paused' => true]);

        (new CommitPriceListImport($candidate->id))->handle(app(ImportActivator::class));

        $this->assertSame(PriceListImportStatus::Preview, $candidate->fresh()->status);
        $this->assertSame($current->id, $config->supplier->fresh()->active_price_list_import_id);
        $this->assertNull($config->fresh()->last_activated_at);
    }

    public function test_mapping_without_saved_sync_settings_preserves_existing_mailbox_imports(): void
    {
        Storage::fake('local');
        [$config] = $this->configured(['settings_confirmed' => false, 'automatic_enabled' => false]);
        SupplierImportProfile::factory()->for($config->supplier, 'supplier')->create(['file_type' => 'csv', 'configuration' => ProfileValidator::defaults()]);
        SupplierSenderAddress::factory()->for($config->supplier, 'supplier')->create(['email' => 'legacy@example.com', 'normalized_email' => 'legacy@example.com']);
        Storage::disk('local')->put('legacy.csv', "name,price\nАспирин,12.5\n");
        $file = new StoredImportFile('local', 'legacy.csv', 'legacy.csv', 'text/csv', Storage::disk('local')->size('legacy.csv'), hash_file('sha256', Storage::disk('local')->path('legacy.csv')));

        $import = app(SupplierPriceListIngestor::class)->ingestFromEmail($file, 'legacy@example.com');

        $this->assertSame(PriceListImportStatus::Completed, $import->fresh()->status);
        $this->assertNull($import->fresh()->source_metadata);
        $this->assertSame(0, SupplierSyncRun::count());
    }

    public function test_scheduler_reconciles_failed_mailbox_import_into_durable_history(): void
    {
        [$config] = $this->configured(['next_check_at' => now()->addDay()]);
        $import = PriceListImport::factory()->for($config->supplier, 'supplier')->create(['source_type' => 'email', 'status' => PriceListImportStatus::Failed, 'failure_stage' => 'preparation']);
        $run = SupplierSyncRun::factory()->create(['supplier_organization_id' => $config->supplier_organization_id, 'trigger' => 'mailbox', 'status' => 'processing']);
        $attempt = SupplierSyncAttempt::factory()->for($run, 'run')->for($import, 'import')->create(['source' => 'email', 'status' => 'processing']);

        $this->artisan('suppliers:sync')->assertSuccessful();

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('import_failed', $attempt->fresh()->reason);
        $this->assertSame('preparation', $attempt->fresh()->metadata['failure_stage']);
    }

    public function test_removing_synchronization_configuration_cannot_bypass_external_activation_policy(): void
    {
        [$config, $partner] = $this->configured();
        $candidate = $this->candidate($config, $partner);
        $config->delete();

        $this->assertSame('configuration_missing', app(SupplierSourcePolicy::class)->reason($candidate));
    }

    public function test_directory_tabs_keep_independent_searches_and_pages(): void
    {
        $admin = $this->admin();
        Organization::factory()->wholesaler()->count(26)->sequence(fn ($sequence): array => ['name' => sprintf('Наш %02d', $sequence->index)])->create();
        ExternalSupplierPartner::factory()->count(26)->sequence(fn ($sequence): array => ['name' => sprintf('Внешний %02d', $sequence->index)])->create();

        $this->actingAs($admin)->get(route('admin.supplier-sync.index', ['q' => 'Внешний', 'page' => 2, 'supplier_q' => 'Наш', 'supplier_page' => 2]))
            ->assertSee('Внешний 25')->assertDontSee('Внешний 00')
            ->assertSee('supplier_page=2', false);
        $this->get(route('admin.supplier-sync.index', ['tab' => 'suppliers', 'q' => 'Не совпадает', 'page' => 2, 'supplier_q' => 'Наш', 'supplier_page' => 2]))
            ->assertSee('Наш 25')->assertDontSee('Наш 00')->assertDontSee('Внешний 25');
    }

    public function test_directory_filters_by_service_and_confirmed_mapping(): void
    {
        [$config, $partner] = $this->configured();
        $partner->update(['name' => 'Подтверждённый внешний']);
        $this->partner(['external_id' => 'unmapped', 'name' => 'Несопоставленный внешний']);
        $this->partner(['source' => 'icorp', 'name' => 'Другой сервис']);

        $this->actingAs($this->admin())->get(route('admin.supplier-sync.index', ['service' => 'pricelist_tj', 'filter' => 'confirmed']))
            ->assertSee('Подтверждённый внешний')->assertDontSee('Несопоставленный внешний')->assertDontSee('Другой сервис');
    }

    public function test_directory_empty_states_distinguish_missing_directory_and_search(): void
    {
        $this->actingAs($this->admin())->get(route('admin.supplier-sync.index'))->assertSee('Справочники ещё не загружены')->assertSee('Загрузить iCorp');
        $this->partner();

        $this->get(route('admin.supplier-sync.index', ['q' => 'Не существует']))->assertSee('Ничего не найдено')->assertSee('Сбросить фильтры')->assertDontSee('Справочники ещё не загружены');
    }

    #[TestWith(['tab', 'invalid'])]
    #[TestWith(['service', 'invalid'])]
    #[TestWith(['filter', 'invalid'])]
    #[TestWith(['page', 0])]
    #[TestWith(['supplier_page', -1])]
    public function test_directory_rejects_invalid_navigation_parameters(string $key, string|int $value): void
    {
        $this->actingAs($this->admin())->getJson(route('admin.supplier-sync.index', [$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
    }

    public function test_mapping_modes_require_an_intentional_choice_and_ignore_inactive_fields(): void
    {
        $partner = $this->partner();
        $supplier = Organization::factory()->wholesaler()->create();
        $this->actingAs($this->admin())->postJson(route('admin.supplier-sync.map', $partner), ['mapping_mode' => '', 'name' => 'Новый', 'confirmed' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('mapping_mode');
        $this->postJson(route('admin.supplier-sync.map', $partner), ['mapping_mode' => 'existing', 'name' => 'Новый', 'confirmed' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_organization_id');
        $this->assertNull($partner->fresh()->supplier_organization_id);

        $this->post(route('admin.supplier-sync.map', $partner), ['mapping_mode' => 'existing', 'supplier_organization_id' => $supplier->id, 'phone' => 'invalid ignored phone', 'confirmed' => 1])->assertRedirect();
        $this->assertSame($supplier->id, $partner->fresh()->supplier_organization_id);
        $this->assertDatabaseMissing('organizations', ['name' => 'Новый']);
    }

    public function test_new_mapping_mode_creates_supplier_even_if_existing_id_was_submitted(): void
    {
        $partner = $this->partner();
        $supplier = Organization::factory()->wholesaler()->create();

        $this->actingAs($this->admin())->postJson(route('admin.supplier-sync.map', $partner), ['mapping_mode' => 'new', 'supplier_organization_id' => $supplier->id, 'confirmed' => 1])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->post(route('admin.supplier-sync.map', $partner), ['mapping_mode' => 'new', 'supplier_organization_id' => $supplier->id, 'name' => 'Создан намеренно', 'confirmed' => 1])->assertRedirect();

        $this->assertSame('Создан намеренно', $partner->fresh()->supplier->name);
        $this->assertNotSame($supplier->id, $partner->fresh()->supplier_organization_id);
    }

    public function test_mapping_error_reopens_only_submitted_form_and_preserves_values(): void
    {
        $partner = $this->partner(['name' => 'Первый']);
        Organization::factory()->wholesaler()->count(2)->create(['name' => 'Первый']);
        $other = $this->partner(['external_id' => 'other', 'name' => 'Второй']);
        $url = route('admin.supplier-sync.index', ['q' => '', 'service' => 'pricelist_tj']);

        $response = $this->actingAs($this->admin())->from($url)->post(route('admin.supplier-sync.map', $partner), [
            '_sync_form' => 'mapping-'.$partner->id, 'mapping_mode' => 'new', 'name' => 'Восстановленное название', 'confirmed' => '',
        ]);
        $response->assertRedirect($url);
        $page = $this->get($url)->assertSee('value="Восстановленное название"', false)->assertSee('id="mapping-'.$partner->id.'-confirmed-error"', false)
            ->assertDontSee('id="mapping-'.$other->id.'-confirmed-error"', false)->assertSee('Совпадает название')->assertSee('Заполните это поле.');
        $this->assertSame(1, substr_count($page->getContent(), 'value="Восстановленное название"'));
        $this->assertMatchesRegularExpression('/id="mapping-'.$partner->id.'"\s+open/', $page->getContent());
        $this->assertNull($partner->fresh()->supplier_organization_id);
    }

    public function test_supplier_tabs_show_checklist_settings_review_and_paginated_history(): void
    {
        [$config, $partner] = $this->configured(['settings_confirmed' => false]);
        $preview = $this->candidate($config, $partner, ['source_published_at' => null, 'source_date_precision' => null]);
        SupplierSyncRun::factory()->count(21)->create(['supplier_organization_id' => $config->supplier_organization_id, 'status' => 'queued']);
        $this->actingAs($this->admin())->get(route('admin.supplier-sync.show', $config->supplier))
            ->assertSee('Проверка готовности')->assertSee('Настройки не сохранены')->assertSee('Готов')->assertSee('В очереди')->assertDontSee('name="freshness_hours"', false);
        $this->get(route('admin.supplier-sync.show', [$config->supplier, 'tab' => 'sources']))->assertRedirect(route('admin.supplier-import-profiles.edit', $config->supplier));
        $this->get(route('admin.supplier-import-profiles.edit', $config->supplier))
            ->assertSee('Первое сохранение настроек')->assertSee('name="freshness_hours"', false)->assertSee('Настроить профиль PriceList-TJ');
        $this->get(route('admin.supplier-sync.show', [$config->supplier, 'tab' => 'review']))
            ->assertSee('Требуется подтверждение даты')->assertSee('Просмотреть строки')->assertSee('Точность даты')->assertSee('Подтвердить дату');
        $this->get(route('admin.supplier-sync.show', [$config->supplier, 'tab' => 'history', 'page' => 2]))
            ->assertSee('tab=history', false)->assertSee('Попытки источников')->assertDontSee('name="published_at"', false);
        $this->getJson(route('admin.supplier-sync.show', [$config->supplier, 'tab' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('tab');
    }

    public function test_profile_error_restores_only_its_profile_and_sources_tab(): void
    {
        [$config, $partner] = $this->configured();
        $other = $this->partner(['source' => 'icorp', 'supplier_organization_id' => $config->supplier_organization_id, 'profile_configuration' => ExternalPriceWorkbook::profile(), 'profile_active' => true]);
        $url = route('admin.supplier-import-profiles.edit', $config->supplier);

        $this->actingAs($this->admin())->from($url)->put(route('admin.supplier-sync.profile', $partner), [
            'expected_supplier_organization_id' => $config->supplier_organization_id, 'expected_profile_version' => $partner->profile_version, 'expected_source' => $partner->source, 'expected_external_id' => $partner->external_id,
            '_sync_form' => 'profile-'.$partner->id, 'profile_active' => 0, 'matching_strategy' => 'sku', 'date_formats' => 'custom-format',
            'minimum_valid_rows' => 0, 'maximum_error_rows' => 0, 'maximum_error_percentage' => 0,
        ])->assertRedirect($url);
        $page = $this->get($url)->assertSee('id="profile-'.$partner->id.'-minimum_valid_rows-error"', false)->assertSee('value="custom-format"', false)
            ->assertDontSee('id="profile-'.$other->id.'-minimum_valid_rows-error"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'value="custom-format"'));
        $this->assertTrue($partner->fresh()->profile_active);
    }

    public function test_settings_nested_errors_have_accessible_select_and_input_associations(): void
    {
        [$config, $partner] = $this->configured();
        $partner->update(['profile_active' => false]);
        $url = route('admin.supplier-import-profiles.edit', $config->supplier);
        $data = $config->only(['automatic_enabled', 'paused', 'fetch_interval_minutes', 'freshness_hours', 'sources']) + ['expected_version' => $config->version, 'selected_source' => 'pricelist_tj'];
        $data['_sync_form'] = 'settings-'.$config->supplier_organization_id;

        $this->actingAs($this->admin())->from($url)->put(route('admin.supplier-sync.update', $config->supplier), $data)->assertRedirect($url);
        $this->get($url)->assertSee('aria-describedby="selected_source-hint selected_source-error"', false)->assertSee('Сначала подтвердите сопоставление');
        $this->assertSame(1, $config->fresh()->version);
    }

    public function test_date_review_error_restores_only_submitted_preview(): void
    {
        [$config, $partner] = $this->configured();
        $preview = $this->candidate($config, $partner);
        $other = $this->candidate($config, $partner);
        $url = route('admin.supplier-sync.show', [$config->supplier, 'tab' => 'review']);

        $this->actingAs($this->admin())->from($url)->post(route('admin.supplier-sync.date', $preview), [
            '_sync_form' => 'date-'.$preview->id, 'published_at' => '2000-01-01T12:34:56', 'evidence' => '',
        ])->assertRedirect($url);
        $page = $this->get($url)->assertSee('value="2000-01-01T12:34:56"', false)
            ->assertSee('aria-describedby="date-'.$preview->id.'-evidence-error"', false)
            ->assertDontSee('id="date-'.$other->id.'-evidence-error"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'value="2000-01-01T12:34:56"'));
        $this->assertArrayNotHasKey('date_confirmed_by', $preview->fresh()->source_metadata);
    }

    public function test_history_discloses_attempts_and_formats_dates_in_dushanbe(): void
    {
        [$config, $partner] = $this->configured();
        $config->supplier->update(['name' => str_repeat('Очень длинное название поставщика ', 6)]);
        $import = $this->candidate($config, $partner);
        $config->supplier->update(['active_price_list_import_id' => $import->id]);
        $run = SupplierSyncRun::factory()->create(['supplier_organization_id' => $config->supplier_organization_id, 'status' => 'review', 'reason' => 'review_missing_date']);
        SupplierSyncAttempt::factory()->for($run, 'run')->for($import, 'import')->create(['source' => 'pricelist_tj', 'status' => 'review', 'reason' => 'review_missing_date',
            'metadata' => ['published_at' => '2026-11-11T06:00:00Z', 'date_precision' => 'second', 'fingerprint' => str_repeat('a', 64)]]);

        $page = $this->actingAs($this->admin())->get(route('admin.supplier-sync.show', [$config->supplier, 'tab' => 'history']))
            ->assertSee('11.11.2026 11:00')->assertSee('Требуется подтверждение даты')->assertSee('До секунды')->assertSee('Технические сведения')->assertSee('Просмотреть импорт');
    }

    public function test_directory_displays_readable_service_status_count_and_local_time(): void
    {
        $this->partner(['source' => 'icorp']);
        Cache::put('external-prices:status:icorp', ['status' => 'connected', 'checked_at' => '2026-11-11T06:00:00Z']);

        $this->actingAs($this->admin())->get(route('admin.supplier-sync.index'))
            ->assertSee('Подключено')->assertSee('11.11.2026 11:00')->assertSee('В справочнике: 1 поставщиков')
            ->assertSee('Повторить подключение')->assertSee('Подключить')->assertDontSee('2026-11-11T06:00:00Z');
    }

    public function test_settings_priority_error_preserves_nested_value_and_field_association(): void
    {
        [$config] = $this->configured();
        $url = route('admin.supplier-import-profiles.edit', $config->supplier);
        $data = $config->only(['automatic_enabled', 'paused', 'fetch_interval_minutes', 'freshness_hours', 'sources']) + ['expected_version' => $config->version, 'selected_source' => 'pricelist_tj'];
        $data['_sync_form'] = 'settings-'.$config->supplier_organization_id;
        $data['sources']['pricelist_tj']['priority'] = 0;

        $this->actingAs($this->admin())->from($url)->put(route('admin.supplier-sync.update', $config->supplier), $data)->assertRedirect($url);
        $this->get($url)->assertSee('aria-describedby="source-pricelist_tj-priority-error"', false)
            ->assertSee('name="sources[pricelist_tj][priority]" type="number" value="0"', false)->assertSee('Допустимое минимальное значение: 1.');
        $this->assertSame(1, $config->fresh()->version);
    }

    public function test_another_suppliers_failed_settings_do_not_restore_on_this_workspace(): void
    {
        [$config] = $this->configured();
        $this->actingAs($this->admin())->withSession(['_old_input' => ['_sync_form' => 'settings-999999', 'freshness_hours' => 777]])
            ->get(route('admin.supplier-sync.show', $config->supplier))->assertSee('Проверка готовности')->assertDontSee('name="freshness_hours"', false);
    }

    public function test_directory_aligns_both_services_with_the_same_supplier_in_one_row(): void
    {
        [$config, $partner] = $this->configured();
        $partner->update(['name' => 'Сервис TJ']);
        $this->partner(['source' => 'icorp', 'supplier_organization_id' => $config->supplier_organization_id, 'name' => 'Сервис iCorp']);

        $this->actingAs($this->admin())->get(route('admin.supplier-sync.index'))
            ->assertSee('Наш поставщик')->assertSee('Источник')->assertSee('Действия')->assertSee('Сервис TJ')->assertSee('Сервис iCorp')
            ->assertSee('Профиль импорта')->assertSee('Удалить iCorp')->assertSee('Удалить PriceList-TJ')
            ->assertViewHas('partners', fn ($partners): bool => $partners->total() === 1);
    }

    public function test_filtering_one_service_retains_the_confirmed_counterpart_in_its_column(): void
    {
        [$config] = $this->configured();
        $this->partner(['source' => 'icorp', 'supplier_organization_id' => $config->supplier_organization_id, 'name' => 'Связанный iCorp']);

        $this->actingAs($this->admin())->get(route('admin.supplier-sync.index', ['service' => 'pricelist_tj', 'filter' => 'confirmed']))
            ->assertSee('Связанный iCorp')->assertViewHas('partners', fn ($partners): bool => $partners->total() === 1);
    }

    public function test_grouped_directory_paginates_supplier_rows_instead_of_service_records(): void
    {
        $suppliers = Organization::factory()->wholesaler()->count(26)->create();
        foreach ($suppliers as $supplier) {
            $this->partner(['supplier_organization_id' => $supplier->id, 'external_id' => 'tj-'.$supplier->id]);
            $this->partner(['source' => 'icorp', 'supplier_organization_id' => $supplier->id, 'external_id' => 'icorp-'.$supplier->id]);
        }

        $this->actingAs($this->admin())->get(route('admin.supplier-sync.index', ['page' => 2]))
            ->assertViewHas('partners', fn ($partners): bool => $partners->total() === 26 && $partners->count() === 1);
    }

    public function test_unmapping_requires_confirmation_and_preserves_the_mapping_on_failure(): void
    {
        [$config, $partner] = $this->configured();

        $this->actingAs($this->admin())->deleteJson(route('admin.supplier-sync.unmap', $partner), ['expected_supplier_organization_id' => $config->supplier_organization_id])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');

        $this->assertSame($config->supplier_organization_id, $partner->fresh()->supplier_organization_id);
        $this->assertSame(1, $config->fresh()->version);
        $this->assertDatabaseMissing('audit_events', ['event' => 'supplier_mapping.removed']);
    }

    public function test_unmapping_removes_only_the_selected_service_and_preserves_the_active_catalog(): void
    {
        [$config, $partner] = $this->configured();
        $other = $this->partner(['source' => 'icorp', 'supplier_organization_id' => $config->supplier_organization_id]);
        $active = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Completed]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        $version = $partner->profile_version;

        $this->actingAs($this->admin())->delete(route('admin.supplier-sync.unmap', $partner), ['confirmed' => 1, 'expected_supplier_organization_id' => $config->supplier_organization_id])->assertRedirect();

        $this->assertNull($partner->fresh()->supplier_organization_id);
        $this->assertSame($version + 1, $partner->fresh()->profile_version);
        $this->assertSame(2, $config->fresh()->version);
        $this->assertSame($config->supplier_organization_id, $other->fresh()->supplier_organization_id);
        $this->assertSame($active->id, $config->supplier->fresh()->active_price_list_import_id);
        $this->assertModelExists($partner);
        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_mapping.removed', 'subject_id' => $partner->id]);
    }

    public function test_unmapping_a_changed_supplier_mapping_is_rejected(): void
    {
        [$config, $partner] = $this->configured();
        $staleSupplier = Organization::factory()->wholesaler()->create();

        $this->actingAs($this->admin())->deleteJson(route('admin.supplier-sync.unmap', $partner), ['confirmed' => 1, 'expected_supplier_organization_id' => $staleSupplier->id])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');

        $this->assertSame($config->supplier_organization_id, $partner->fresh()->supplier_organization_id);
        $this->assertSame(1, $config->fresh()->version);
    }

    public function test_non_administrator_cannot_remove_supplier_mappings(): void
    {
        [$config, $partner] = $this->configured();

        $this->actingAs(User::factory()->create())->delete(route('admin.supplier-sync.unmap', $partner), ['confirmed' => 1, 'expected_supplier_organization_id' => $config->supplier_organization_id])->assertForbidden();

        $this->assertSame($config->supplier_organization_id, $partner->fresh()->supplier_organization_id);
    }

    public function test_unmapping_errors_restore_only_the_submitted_service_confirmation(): void
    {
        [$config, $partner] = $this->configured();
        $other = $this->partner(['source' => 'icorp', 'supplier_organization_id' => $config->supplier_organization_id]);
        $url = route('admin.supplier-sync.index', ['filter' => 'confirmed']);

        $this->actingAs($this->admin())->from($url)->delete(route('admin.supplier-sync.unmap', $partner), ['_sync_form' => 'unmap-'.$partner->id, 'expected_supplier_organization_id' => $config->supplier_organization_id])->assertRedirect($url);
        $this->get($url)->assertSee('id="unmap-'.$partner->id.'-confirmed-error"', false)->assertDontSee('id="unmap-'.$other->id.'-confirmed-error"', false);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
    }

    private function partner(array $attributes = []): ExternalSupplierPartner
    {
        return ExternalSupplierPartner::factory()->create(array_replace(['source' => 'pricelist_tj', 'external_id' => 'supplier', 'name' => 'Поставщик', 'phones' => [], 'discovered_at' => now()], $attributes));
    }

    /** @return array{SupplierSynchronization, ExternalSupplierPartner} */
    private function configured(array $attributes = []): array
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $sources = SupplierSynchronization::defaultSources();
        $sources['email']['enabled'] = false;
        $sources['icorp']['enabled'] = false;
        $config = SupplierSynchronization::factory()->create(array_replace(['supplier_organization_id' => $supplier->id, 'sources' => $sources, 'automatic_enabled' => true, 'paused' => false, 'version' => 1, 'settings_confirmed' => true], $attributes));
        $config->refresh();
        $partner = $this->partner(['supplier_organization_id' => $supplier->id, 'profile_active' => true, 'profile_version' => 1, 'profile_configuration' => ExternalPriceWorkbook::profile()]);

        return [$config, $partner];
    }

    private function candidate(SupplierSynchronization $config, ExternalSupplierPartner $partner, array $attributes = []): PriceListImport
    {
        return PriceListImport::factory()->for($config->supplier, 'supplier')->create(array_replace([
            'source_type' => 'pricelist_tj', 'status' => PriceListImportStatus::Preview,
            'profile_snapshot' => ExternalPriceWorkbook::profile(), 'valid_rows' => 1, 'error_rows' => 0, 'total_rows' => 1,
            'source_published_at' => now()->subHour(), 'source_date_precision' => 'second',
            'source_metadata' => ['configuration_version' => $config->version, 'trigger' => 'automatic', 'partner_id' => $partner->id, 'profile_version' => $partner->profile_version],
        ], $attributes));
    }

    private function item(string $id = 'one'): array
    {
        return ['id' => $id, 'companyId' => 'supplier', 'medicine' => 'Аспирин '.$id, 'manufacturer' => 'Фирма', 'price' => 9, 'originalPrice' => 10, 'discountPercent' => 10, 'qty' => 3, 'expiry' => null];
    }

    private function fakePrices(?string $date, ?array $items = null): void
    {
        config(['external-prices.pricelist_tj.phone' => '927430570', 'external-prices.pricelist_tj.password' => 'test']);
        Http::preventStrayRequests();
        $items ??= [$this->item()];
        Http::fake([
            'pricelist-tj.com/api/auth/login' => Http::response(['token' => 'private-token']),
            'pricelist-tj.com/api/companies/supplier?*' => Http::response([
                'company' => ['id' => 'supplier', 'name' => 'Поставщик', 'lastPriceUpdate' => $date], 'restricted' => false,
                'priceItems' => $items, 'myDiscount' => 10, 'pagination' => ['page' => 1, 'total' => count($items), 'totalPages' => 1],
            ]),
        ]);
    }
}
