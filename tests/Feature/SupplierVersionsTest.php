<?php

namespace Tests\Feature;

use App\DataTransferObjects\IngestionContext;
use App\DataTransferObjects\StoredImportFile;
use App\Enums\PriceListImportSource;
use App\Enums\PriceListImportStatus;
use App\Jobs\DownloadExternalPricePage;
use App\Jobs\NotifyExternalServiceIncident;
use App\Jobs\PreparePriceListImport;
use App\Jobs\SynchronizeSupplier;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ExternalAvailability;
use App\Models\ExternalDownload;
use App\Models\ExternalListingRun;
use App\Models\ExternalSupplierPartner;
use App\Models\GmailImportReceipt;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\SupplierEmailDelivery;
use App\Models\SupplierImportProfile;
use App\Models\SupplierSenderAddress;
use App\Models\SupplierSynchronization;
use App\Models\SupplierVersionClaim;
use App\Models\User;
use App\Services\ExternalPriceDownload;
use App\Services\ExternalPriceWorkbook;
use App\Services\PriceList\ImportActivator;
use App\Services\PriceListTjClient;
use App\Services\RenderedSupplierListing;
use App\Services\SupplierEmailExclusions;
use App\Services\SupplierInboxEvidence;
use App\Services\SupplierPriceListIngestor;
use App\Services\SupplierSourcePolicy;
use App\Services\SupplierSynchronizationService;
use App\Services\SupplierVersions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupplierVersionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['price-list-imports.daily_versions' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00', 'Asia/Dushanbe'));
    }

    #[TestWith(['price_18.11.2026.xlsx', '2026-11-18', 'filename'])]
    #[TestWith(['price_18-11-2026.xlsx', '2026-11-18', 'filename'])]
    #[TestWith(['price_18_11_2026.xlsx', '2026-11-18', 'filename'])]
    #[TestWith(['price_2026-11-17.xlsx', '2026-11-17', 'filename'])]
    #[TestWith(['price_31.11.2026.xlsx', '2026-11-18', 'arrival'])]
    #[TestWith(['price.xlsx', '2026-11-18', 'arrival'])]
    public function test_resolves_filename_separators_and_local_arrival_day(string $filename, string $day, string $origin): void
    {
        $result = app(SupplierVersions::class)->publication($filename, [], CarbonImmutable::parse('2026-11-17T20:05:00Z'));

        $this->assertSame($day, $result['date']->toDateString());
        $this->assertSame($origin, $result['origin']);
    }

    public function test_claim_blocks_competing_source_and_same_day_success_even_with_different_precision(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $versions = app(SupplierVersions::class);
        $date = CarbonImmutable::parse('2026-11-18', 'Asia/Dushanbe');

        DB::transaction(function () use ($supplier, $versions, $date): void {
            Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $this->assertNotNull($versions->claim($supplier, $date, 'icorp'));
            $this->assertNull($versions->claim($supplier, $date->addHours(12), 'pricelist_tj'));
        });
        $supplier->update(['successful_price_list_day' => '2026-11-18']);

        $this->assertSame('already_imported_day', $versions->freshnessReason($supplier, $date->addHours(23)));
        $this->travel(1)->days();
        $this->assertNull($versions->freshnessReason($supplier, $date->addDay()));
    }

    #[TestWith(['17.11.2026', '18.11.2026, 09:05', 'pricelist_tj'])]
    #[TestWith(['18.11.2026', '17.11.2026, 23:59', 'icorp'])]
    #[TestWith(['18.11.2026', '18.11.2026, 09:05', 'pricelist_tj'])]
    public function test_newest_day_wins_and_pricelist_tj_wins_ties(string $icorp, string $pricelist, string $winner): void
    {
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class]);
        $config = $this->configuration();
        $this->listing($config, 'icorp', $icorp);
        $this->listing($config, 'pricelist_tj', $pricelist);

        $run = app(SupplierSynchronizationService::class)->request($config->supplier);
        app(SupplierSynchronizationService::class)->process($run);

        $this->assertDatabaseHas('external_downloads', ['supplier_organization_id' => $config->supplier_organization_id, 'source' => $winner]);
        $this->assertSame(1, ExternalDownload::count());
        Queue::assertPushed(DownloadExternalPricePage::class, 1);
    }

    public function test_stale_or_incomplete_snapshots_cannot_queue_imports_or_establish_absence(): void
    {
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class]);
        $config = $this->configuration();
        $this->listing($config, 'icorp', '18.11.2026');
        ExternalListingRun::where('source', 'icorp')->update(['status' => 'failed']);
        $run = app(SupplierSynchronizationService::class)->request($config->supplier);

        app(SupplierSynchronizationService::class)->process($run);

        $this->assertSame(0, ExternalDownload::count());
        Queue::assertNotPushed(DownloadExternalPricePage::class);
        $this->assertDatabaseHas('external_availabilities', ['available' => true]);
    }

    public function test_existing_inbox_evidence_reserves_version_without_downloading(): void
    {
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class]);
        $config = $this->configuration();
        $this->listing($config, 'icorp', '18.11.2026');
        SupplierEmailDelivery::factory()->create(['supplier_organization_id' => $config->supplier_organization_id, 'mailbox_email' => 'inbox@example.com', 'gmail_message_id' => 'saved',
            'published_day' => '2026-11-18', 'received_at' => now(), 'date_origin' => 'filename', 'date_precision' => 'day', 'manifest' => []]);
        $run = app(SupplierSynchronizationService::class)->request($config->supplier);

        app(SupplierSynchronizationService::class)->process($run);

        $this->assertDatabaseHas('supplier_version_claims', ['supplier_organization_id' => $config->supplier_organization_id, 'source' => 'email']);
        $this->assertSame('reserved_for_inbox', $run->fresh()->reason);
        Queue::assertNotPushed(DownloadExternalPricePage::class);
    }

    public function test_exhausted_download_does_not_restart_on_hourly_runs_and_falls_back(): void
    {
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class]);
        $config = $this->configuration();
        $this->listing($config, 'icorp', '18.11.2026');
        $this->listing($config, 'pricelist_tj', '18.11.2026, 10:00');
        $service = app(SupplierSynchronizationService::class);
        $run = $service->request($config->supplier);
        $service->process($run);
        $download = ExternalDownload::firstOrFail();
        $download->update(['status' => 'failed', 'retry_count' => 4, 'reason' => 'retry_exhausted']);
        $run->attempts()->update(['status' => 'failed']);
        SupplierVersionClaim::where('external_download_id', $download->id)->update(['state' => 'failed']);

        $service->process($run->fresh());

        $this->assertSame(2, ExternalDownload::count());
        $this->assertDatabaseHas('external_downloads', ['source' => 'icorp', 'status' => 'pending']);
        $this->assertSame(4, $download->fresh()->retry_count);
        $this->assertDatabaseHas('supplier_version_claims', ['source' => 'icorp']);
    }

    public function test_manual_uploads_work_for_excluded_unmapped_supplier_and_skip_same_day(): void
    {
        Queue::fake([PreparePriceListImport::class]);
        Storage::fake('local');
        $profile = SupplierImportProfile::factory()->create(['file_type' => 'csv']);
        $supplier = $profile->supplier;
        $supplier->update(['external_fetch_enabled' => false]);
        Storage::disk('local')->put('upload.csv', "Aspirin,10\n");
        $file = new StoredImportFile('local', 'upload.csv', 'prices.csv', 'text/csv', 11, str_repeat('a', 64));
        $context = new IngestionContext(PriceListImportSource::Manual, receivedAt: CarbonImmutable::now());

        $import = app(SupplierPriceListIngestor::class)->ingest($supplier, $file, $context);

        $this->assertSame(PriceListImportStatus::Pending, $import->status);
        $this->assertDatabaseHas('supplier_version_claims', ['price_list_import_id' => $import->id, 'source' => 'manual']);
        $this->assertSame('arrival', $import->source_metadata['publication_origin']);
        Queue::assertPushed(PreparePriceListImport::class, 1);
        $import->update(['status' => PriceListImportStatus::Completed]);
        $supplier->update(['successful_price_list_day' => '2026-11-18']);
        Storage::disk('local')->put('second.csv', "Other,20\n");
        $second = app(SupplierPriceListIngestor::class)->ingest($supplier, new StoredImportFile('local', 'second.csv', 'second.csv', 'text/csv', 9, str_repeat('b', 64)), $context);
        $this->assertSame('version_skipped', $second->failure_stage);
        $this->assertSame('already_imported_day', $second->failure_message);
    }

    public function test_late_email_links_completed_version_and_waits_for_active_external_work(): void
    {
        $profile = SupplierImportProfile::factory()->create();
        $supplier = $profile->supplier;
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['source_published_at' => now(), 'status' => PriceListImportStatus::Completed]);
        $supplier->update(['successful_price_list_day' => '2026-11-18']);
        SupplierVersionClaim::factory()->create(['supplier_organization_id' => $supplier->id, 'published_day' => '2026-11-18', 'source' => 'icorp', 'state' => 'completed', 'price_list_import_id' => $import->id]);
        $receipt = GmailImportReceipt::factory()->create(['supplier_organization_id' => $supplier->id, 'received_at' => now()]);

        $this->assertFalse(app(SupplierInboxEvidence::class)->ready($receipt, 'prices.csv'));

        $this->assertSame('skipped', $receipt->fresh()->status);
        $this->assertSame($import->id, $receipt->fresh()->price_list_import_id);
        $supplier->update(['successful_price_list_day' => null]);
        $import->update(['status' => PriceListImportStatus::Processing]);
        SupplierVersionClaim::query()->update(['state' => 'processing']);
        $receipt->update(['status' => 'pending', 'price_list_import_id' => null]);
        $this->assertFalse(app(SupplierInboxEvidence::class)->ready($receipt->fresh(), 'prices.csv'));
        $this->assertSame('version_processing', $receipt->fresh()->reason);
    }

    public function test_monthly_deliveries_are_deduplicated_and_daily_assessment_preserves_override(): void
    {
        $profile = SupplierImportProfile::factory()->create(['file_type' => 'csv']);
        $sender = SupplierSenderAddress::factory()->for($profile->supplier, 'supplier')->create();
        $inbox = app(SupplierInboxEvidence::class);
        foreach (['a', 'b', 'c'] as $id) {
            for ($i = 0; $i < 2; $i++) {
                $inbox->record('inbox@example.com', $id, $sender->email, CarbonImmutable::parse('2026-10-20', 'Asia/Dushanbe'), [['filename' => 'prices.csv']]);
            }
        }
        $inbox->record('inbox@example.com', 'november', $sender->email, now(), [['filename' => 'prices.csv']]);

        app(SupplierEmailExclusions::class)->assess(seed: true);

        $supplier = $profile->supplier->fresh();
        $this->assertFalse($supplier->external_fetch_enabled);
        $this->assertSame(3, $supplier->external_fetch_evidence['deliveries']);
        $supplier->update(['external_fetch_enabled' => true, 'external_fetch_overridden' => true]);
        app(SupplierEmailExclusions::class)->assess(seed: true);
        $this->assertTrue($supplier->fresh()->external_fetch_enabled);
    }

    public function test_activation_policy_rechecks_claim_and_watermark_for_manual_imports(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['source_type' => 'manual', 'source_published_at' => now(), 'valid_rows' => 1]);
        app(SupplierVersions::class)->attach($import);
        $this->assertNull(app(SupplierSourcePolicy::class)->reason($import, $supplier));
        $supplier->update(['successful_price_list_day' => '2026-11-18']);

        $this->assertSame('already_imported_day', app(SupplierSourcePolicy::class)->reason($import, $supplier));
    }

    public function test_upload_controls_render_for_excluded_supplier_without_mapping(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Organization::factory()->wholesaler()->create(['external_fetch_enabled' => false]);

        $this->actingAs($admin)->get(route('admin.supplier-sync.show', $supplier))->assertSee('Загрузить прайс-лист')->assertSee('published_date');
        $this->actingAs($admin)->get(route('admin.supplier-sync.show', [$supplier, 'tab' => 'sources']))->assertSee('Внешняя загрузка')->assertDontSee('Предпочтение только для равных дат');
    }

    public function test_cutover_advances_successful_day_and_preserves_basket_on_newer_version(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $actor = User::factory()->wholesaler($supplier)->create();
        $old = PriceListImport::factory()->for($supplier, 'supplier')->create(['source_type' => 'manual', 'status' => PriceListImportStatus::Completed, 'source_published_at' => '2026-11-17', 'inventory_at' => '2026-11-17']);
        $medicine = Medicine::factory()->create(['supplier_organization_id' => $supplier->id]);
        $offer = Offer::factory()->for($supplier, 'organization')->for($medicine)->create(['price_list_import_id' => $old->id, 'quantity' => 10, 'is_active' => true]);
        $supplier->update(['active_price_list_import_id' => $old->id, 'successful_price_list_day' => '2026-11-17']);
        $cart = Cart::create(['user_id' => $actor->id]);
        $item = CartItem::create(['cart_id' => $cart->id, 'offer_id' => $offer->id, 'quantity' => 7, 'unit_price' => 10, 'snapshot' => ['original' => true]]);
        $candidate = PriceListImport::factory()->for($supplier, 'supplier')->create(['source_type' => 'manual', 'status' => PriceListImportStatus::Preview, 'source_published_at' => '2026-11-18', 'inventory_at' => '2026-11-18', 'valid_rows' => 1]);
        PriceListImportRow::create(['price_list_import_id' => $candidate->id, 'source_row' => 2, 'raw_values' => [], 'parsed_values' => ['name' => 'Новый', 'normalized_name' => 'новый', 'normalized_sku' => null, 'sku' => null, 'price' => 20, 'quantity' => null, 'total' => null, 'expiration' => null], 'disposition' => 'valid', 'planned_action' => 'create', 'errors' => [], 'warnings' => []]);
        app(SupplierVersions::class)->attach($candidate);

        app(ImportActivator::class)->activate($candidate, $actor);

        $this->assertSame('2026-11-18', $supplier->fresh()->successful_price_list_day);
        $this->assertSame(PriceListImportStatus::Completed, $candidate->fresh()->status);
        $this->assertDatabaseHas('supplier_version_claims', ['price_list_import_id' => $candidate->id, 'state' => 'completed']);
        $this->assertSame(7, $item->fresh()->quantity);
        $this->assertSame(['original' => true], $item->fresh()->snapshot);
        $this->assertSame('missing_replacement', $item->fresh()->availability_reason);
    }

    public function test_incomplete_listing_never_changes_existing_snapshot(): void
    {
        $config = $this->configuration();
        $this->listing($config, 'icorp', '18.11.2026');
        $run = ExternalListingRun::firstOrFail();
        $run->update(['status' => 'pending', 'pages' => [1 => ['partners' => [], 'received' => 0, 'total_pages' => 2]]]);

        try {
            app(RenderedSupplierListing::class)->publish($run);
            $this->fail('Incomplete snapshot must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('incomplete_listing', $exception->getMessage());
        }
        $this->assertDatabaseHas('external_availabilities', ['available' => true]);
    }

    public function test_rollout_keeps_dispatch_paused_until_listing_dry_run_and_survives_cache_clear(): void
    {
        $supplier = Organization::factory()->wholesaler()->create(['name' => 'Мижгона']);
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['original_filename' => 'price_17-11-2026.csv', 'status' => PriceListImportStatus::Completed, 'source_published_at' => null]);

        $this->artisan('suppliers:rollout-versions', ['--prepare' => true])->assertSuccessful();

        $this->assertSame('2026-11-17', $supplier->fresh()->successful_price_list_day);
        $this->assertFalse($supplier->fresh()->external_fetch_enabled);
        $this->assertSame('completed', SupplierVersionClaim::where('price_list_import_id', $import->id)->value('state'));
        $this->assertTrue(app(SupplierVersions::class)->paused());
        Cache::flush();
        config(['price-list-imports.daily_versions' => false]);
        $this->assertTrue(app(SupplierVersions::class)->enabled());
        $this->assertTrue(app(SupplierVersions::class)->paused());
        $this->artisan('suppliers:rollout-versions', ['--enable' => true])->assertFailed();
        foreach (['icorp', 'pricelist_tj'] as $source) {
            ExternalListingRun::factory()->create(['source' => $source, 'scan_hour' => now()->startOfHour(), 'status' => 'completed']);
        }
        $this->artisan('suppliers:rollout-versions', ['--enable' => true])->assertSuccessful();
        $this->assertFalse(app(SupplierVersions::class)->paused());
    }

    public function test_undated_newer_delivery_can_follow_a_failed_inbox_version(): void
    {
        Queue::fake([PreparePriceListImport::class]);
        Storage::fake('local');
        $profile = SupplierImportProfile::factory()->create(['file_type' => 'csv']);
        $supplier = $profile->supplier;
        Storage::disk('local')->put('failed.csv', 'invalid');
        $failed = app(SupplierPriceListIngestor::class)->ingest($supplier, new StoredImportFile('local', 'failed.csv', 'prices.csv', 'text/csv', 7, str_repeat('a', 64)), new IngestionContext(PriceListImportSource::Email, receivedAt: CarbonImmutable::parse('2026-11-17', 'Asia/Dushanbe')));
        $failed->update(['status' => PriceListImportStatus::Failed]);
        Storage::disk('local')->put('new.csv', 'new');

        $new = app(SupplierPriceListIngestor::class)->ingest($supplier, new StoredImportFile('local', 'new.csv', 'prices.csv', 'text/csv', 3, str_repeat('b', 64)), new IngestionContext(PriceListImportSource::Email, receivedAt: CarbonImmutable::now()));

        $this->assertSame(PriceListImportStatus::Pending, $new->status);
        $this->assertNull($supplier->fresh()->successful_price_list_day);
        $this->assertSame(2, SupplierVersionClaim::where('supplier_organization_id', $supplier->id)->count());
        Queue::assertPushed(PreparePriceListImport::class, 2);
    }

    public function test_missing_profile_upload_uses_existing_sample_configuration_flow(): void
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();
        $supplier = Organization::factory()->wholesaler()->create(['external_fetch_enabled' => false]);

        $this->actingAs($admin)->post(route('price-list-imports.store'), ['supplier_organization_id' => $supplier->id, 'file' => UploadedFile::fake()->createWithContent('prices.csv', "name,price\nAspirin,10\n")])->assertRedirect(route('admin.supplier-sync.show', [$supplier, 'tab' => 'sources']));

        $this->assertNotNull($supplier->fresh()->importProfile->sample_metadata);
        $this->assertSame(0, PriceListImport::where('supplier_organization_id', $supplier->id)->count());
    }

    public function test_workbook_date_releases_provisional_arrival_reservation(): void
    {
        $profile = SupplierImportProfile::factory()->create();
        $supplier = $profile->supplier;
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['source_type' => 'email', 'source_published_at' => now(), 'message_id' => 'workbook-date', 'source_metadata' => ['publication_origin' => 'arrival']]);
        $receipt = GmailImportReceipt::factory()->create(['supplier_organization_id' => $supplier->id, 'price_list_import_id' => $import->id]);
        SupplierVersionClaim::factory()->create(['supplier_organization_id' => $supplier->id, 'published_day' => '2026-11-18', 'source' => 'email', 'gmail_import_receipt_id' => $receipt->id]);
        SupplierEmailDelivery::factory()->create(['supplier_organization_id' => $supplier->id, 'mailbox_email' => $receipt->mailbox_email, 'gmail_message_id' => 'workbook-date', 'received_at' => now(), 'published_day' => '2026-11-18', 'date_origin' => 'arrival', 'date_precision' => 'day', 'manifest' => []]);

        app(SupplierVersions::class)->resolveWorkbookDay($import, ['date' => CarbonImmutable::parse('2026-11-17', 'Asia/Dushanbe'), 'precision' => 'day', 'origin' => 'cell']);

        $this->assertDatabaseMissing('supplier_version_claims', ['supplier_organization_id' => $supplier->id, 'published_day' => '2026-11-18']);
        $this->assertDatabaseHas('supplier_email_deliveries', ['supplier_organization_id' => $supplier->id, 'published_day' => '2026-11-17', 'date_origin' => 'cell']);
        $this->assertSame('2026-11-17', $import->fresh()->source_published_at->toDateString());
    }

    #[TestWith([2, true])]
    #[TestWith([3, false])]
    public function test_calendar_month_email_threshold_excludes_only_at_three_deliveries(int $count, bool $enabled): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        foreach (range(1, $count) as $index) {
            SupplierEmailDelivery::factory()->create(['supplier_organization_id' => $supplier->id, 'mailbox_email' => 'monthly@example.com', 'gmail_message_id' => 'october-'.$index, 'received_at' => '2026-10-01 00:00:00', 'published_day' => '2026-10-01', 'date_origin' => 'arrival', 'date_precision' => 'day', 'manifest' => []]);
        }
        foreach (['2026-09-30 23:59:59', '2026-11-01 00:00:00'] as $outside) {
            SupplierEmailDelivery::factory()->create(['supplier_organization_id' => $supplier->id, 'mailbox_email' => 'monthly@example.com', 'gmail_message_id' => $outside, 'received_at' => $outside, 'published_day' => substr($outside, 0, 10), 'date_origin' => 'arrival', 'date_precision' => 'day', 'manifest' => []]);
        }

        app(SupplierEmailExclusions::class)->assess(seed: true);

        $this->assertSame($enabled, $supplier->fresh()->external_fetch_enabled);
        $this->assertSame($count, $supplier->fresh()->external_fetch_evidence['deliveries']);
    }

    public function test_external_transport_failures_stop_after_three_scheduled_retries(): void
    {
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class, NotifyExternalServiceIncident::class]);
        $config = $this->configuration();
        $this->listing($config, 'pricelist_tj', '18.11.2026, 10:00');
        $run = app(SupplierSynchronizationService::class)->request($config->supplier);
        app(SupplierSynchronizationService::class)->process($run);
        $download = ExternalDownload::firstOrFail();
        $this->mock(PriceListTjClient::class)->shouldReceive('pricePage')->times(4)->andThrow(new ConnectionException('transport'));
        $service = app(ExternalPriceDownload::class);
        foreach ([300, 600, 1200] as $index => $delay) {
            $service->process($download->id);
            $download->refresh();
            $this->assertSame('retry', $download->status);
            $this->assertSame($index + 1, $download->retry_count);
            $this->assertGreaterThanOrEqual($delay, now()->diffInSeconds($download->next_retry_at));
            $this->travelTo($download->next_retry_at);
        }

        $service->process($download->id);

        $this->assertSame('failed', $download->fresh()->status);
        $this->assertSame(4, $download->fresh()->retry_count);
        $this->assertSame('failed', SupplierVersionClaim::where('external_download_id', $download->id)->value('state'));
    }

    public function test_rollout_replaces_unproven_receipt_timestamp_with_filename_publication_evidence(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['source_type' => 'email', 'original_filename' => 'prices_15.11.2026.csv', 'status' => PriceListImportStatus::Completed,
            'source_published_at' => '2026-11-17 12:00:00', 'source_date_precision' => 'second', 'received_at' => '2026-11-17 12:00:00', 'source_metadata' => []]);

        $this->artisan('suppliers:rollout-versions', ['--prepare' => true])->assertSuccessful();

        $this->assertSame('2026-11-15', $import->fresh()->source_published_at->toDateString());
        $this->assertSame('day', $import->fresh()->source_date_precision);
        $this->assertSame('filename', $import->fresh()->source_metadata['publication_origin']);
        $this->assertStringContainsString('2026-11-17', $import->fresh()->source_metadata['version_reconciliation']['original_published_at']);
        $this->assertSame('2026-11-15', $supplier->fresh()->successful_price_list_day);
    }

    public function test_newer_activation_terminates_older_external_run_instead_of_waiting_forever(): void
    {
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class]);
        $config = $this->configuration();
        $this->listing($config, 'icorp', '17.11.2026');
        $service = app(SupplierSynchronizationService::class);
        $run = $service->request($config->supplier);
        $service->process($run);
        $older = PriceListImport::factory()->for($config->supplier, 'supplier')->create(['source_type' => 'icorp', 'status' => PriceListImportStatus::Preview, 'source_published_at' => '2026-11-17']);
        $run->attempts()->where('source', 'icorp')->update(['price_list_import_id' => $older->id, 'status' => 'processing']);
        $config->supplier->update(['successful_price_list_day' => '2026-11-18']);

        $service->process($run->fresh());

        $this->assertNull($run->fresh()->active_supplier_id);
        $this->assertSame('already_imported_day', $run->fresh()->reason);
        $this->assertSame('version_skipped', $older->fresh()->failure_stage);
    }

    public function test_direct_extraction_is_retired_after_daily_version_rollout(): void
    {
        Http::preventStrayRequests();

        $this->artisan('external-prices:fetch', ['source' => 'icorp'])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_administrator_override_is_audited_and_independent_of_mapping_or_profile(): void
    {
        $admin = User::factory()->admin()->create();
        $supplier = Organization::factory()->wholesaler()->create();

        $this->actingAs($admin)->put(route('admin.supplier-sync.external-fetch', $supplier), ['external_fetch_enabled' => false, 'exclusion_reason' => 'Получаем по почте', 'expected_version' => 0])->assertRedirect();

        $this->assertTrue($supplier->fresh()->external_fetch_overridden);
        $this->assertFalse($supplier->fresh()->external_fetch_enabled);
        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_external_fetch.overridden', 'subject_id' => $supplier->id, 'user_id' => $admin->id]);
        $this->actingAs($admin)->get(route('admin.supplier-sync.show', [$supplier, 'tab' => 'sources']))->assertSee('История настроек внешней загрузки')->assertSee('Получаем по почте');
    }

    #[TestWith(['2026-11-18T11:30:00+05:00', 'completed'])]
    #[TestWith(['2026-11-17T11:30:00+05:00', 'failed'])]
    public function test_download_activates_listing_day_or_rejects_changed_catalog_day(string $downloadDate, string $expected): void
    {
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class, NotifyExternalServiceIncident::class]);
        Storage::fake('local');
        $config = $this->configuration();
        $this->listing($config, 'pricelist_tj', '18.11.2026, 10:00');
        $run = app(SupplierSynchronizationService::class)->request($config->supplier);
        app(SupplierSynchronizationService::class)->process($run);
        $download = ExternalDownload::firstOrFail();
        $this->mock(PriceListTjClient::class)->shouldReceive('pricePage')->once()->andReturn([
            'company' => ['id' => $download->external_id, 'name' => 'Test supplier', 'lastPriceUpdate' => $downloadDate],
            'items' => [['external_id' => 'one', 'name' => 'Аспирин', 'price' => '10', 'quantity' => '5']], 'total' => 1, 'total_pages' => 1, 'account_discount' => null,
        ]);

        app(ExternalPriceDownload::class)->process($download->id);

        $this->assertSame($expected, $download->fresh()->status);
        if ($expected === 'completed') {
            $this->assertSame('2026-11-18', $config->supplier->fresh()->successful_price_list_day);
            $this->assertSame('completed', SupplierVersionClaim::where('external_download_id', $download->id)->value('state'));
            $this->assertSame(PriceListImportStatus::Completed, PriceListImport::firstOrFail()->status);
        } else {
            $this->assertNull($config->supplier->fresh()->successful_price_list_day);
            $this->assertSame(0, PriceListImport::count());
            $this->assertDatabaseHas('external_service_states', ['source' => 'pricelist_tj', 'status' => 'paused', 'reason' => 'extraction']);
        }
    }

    public function test_administrator_retry_reuses_exhausted_download_with_current_approved_configuration(): void
    {
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class]);
        $config = $this->configuration();
        $this->listing($config, 'icorp', '18.11.2026');
        $run = app(SupplierSynchronizationService::class)->request($config->supplier);
        app(SupplierSynchronizationService::class)->process($run);
        $download = ExternalDownload::firstOrFail();
        $download->update(['status' => 'failed', 'retry_count' => 4]);
        SupplierVersionClaim::where('external_download_id', $download->id)->update(['state' => 'failed']);
        $config->increment('version');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.supplier-sync.download-retry', $download))->assertRedirect();

        $this->assertSame(1, ExternalDownload::count());
        $this->assertSame('retry', $download->fresh()->status);
        $this->assertSame(0, $download->fresh()->retry_count);
        $this->assertSame($config->fresh()->version, $download->fresh()->snapshot['configuration_version']);
        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_download.retry_requested', 'subject_id' => $download->id, 'user_id' => $admin->id]);
    }

    public function test_old_manual_listing_confirmation_cannot_override_visible_listing_date(): void
    {
        $config = $this->configuration();
        $this->listing($config, 'icorp', '18.11.2026');
        $observation = ExternalAvailability::firstOrFail();
        $observation->update(['published_at' => '2026-11-17', 'evidence' => array_replace($observation->evidence, ['confirmed_published_at' => '2026-11-17', 'date_evidence' => 'Old policy confirmation'])]);
        $run = ExternalListingRun::firstOrFail();

        app(RenderedSupplierListing::class)->publish($run);
        app(RenderedSupplierListing::class)->publish($run->fresh());

        $this->assertSame('2026-11-18', $observation->fresh()->published_at->toDateString());
        $this->assertSame('2026-11-17', $observation->fresh()->evidence['legacy_confirmation']['confirmed_published_at']);
    }

    private function configuration(): SupplierSynchronization
    {
        return SupplierSynchronization::factory()->create(['automatic_enabled' => true, 'selection_mode' => 'daily_versions']);
    }

    private function listing(SupplierSynchronization $config, string $source, string $raw): void
    {
        $partner = ExternalSupplierPartner::factory()->create(['supplier_organization_id' => $config->supplier_organization_id, 'source' => $source, 'profile_active' => true, 'profile_configuration' => ExternalPriceWorkbook::profile()]);
        $run = ExternalListingRun::factory()->create(['source' => $source, 'scan_hour' => now()->startOfHour(), 'pages' => [1 => ['partners' => [
            ['source' => $source, 'external_id' => $partner->external_id, 'name' => 'Test supplier', 'phones' => [], 'published_at' => $raw, 'available' => true, 'catalog_id' => 'catalog'],
        ], 'received' => 1, 'total_pages' => 1]]]);
        app(RenderedSupplierListing::class)->publish($run);
    }
}
