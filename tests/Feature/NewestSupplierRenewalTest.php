<?php

namespace Tests\Feature;

use App\DataTransferObjects\IngestionContext;
use App\DataTransferObjects\StoredImportFile;
use App\Enums\PriceListImportSource;
use App\Enums\PriceListImportStatus;
use App\Jobs\DownloadExternalPricePage;
use App\Jobs\NotifyExternalServiceIncident;
use App\Jobs\RefreshExternalSupplierDirectory;
use App\Jobs\SynchronizeSupplier;
use App\Models\ExternalAvailability;
use App\Models\ExternalDownload;
use App\Models\ExternalServiceIncident;
use App\Models\ExternalServiceState;
use App\Models\ExternalSupplierPartner;
use App\Models\GmailImportReceipt;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierImportProfile;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use App\Models\User;
use App\Services\ExternalDownloadLease;
use App\Services\ExternalPriceDownload;
use App\Services\ExternalPriceWorkbook;
use App\Services\ExternalServiceControl;
use App\Services\ExternalServiceUnavailable;
use App\Services\ExternalSupplierDirectory;
use App\Services\NewestSupplierCandidates;
use App\Services\PriceList\ImportActivator;
use App\Services\PriceListTjClient;
use App\Services\SupplierPriceListIngestor;
use App\Services\SupplierSourcePolicy;
use App\Services\SupplierSynchronizationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NewestSupplierRenewalTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00', 'Asia/Dushanbe'));
        Sleep::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class, NotifyExternalServiceIncident::class]);
    }

    public function test_newer_lower_priority_beats_fresh_preferred_mail(): void
    {
        [$config, $partner] = $this->configured();
        $active = $this->candidate($config, $partner, ['source_type' => 'email', 'status' => PriceListImportStatus::Completed, 'source_published_at' => now()->subHours(2)]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        $this->fakeExternal(now()->subHour()->toIso8601String());
        $run = $this->process($config);
        $download = ExternalDownload::firstOrFail();
        app(ExternalPriceDownload::class)->process($download->id);
        app(SupplierSynchronizationService::class)->process($run->fresh());

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNotSame($active->id, $config->supplier->fresh()->active_price_list_import_id);
        $this->assertSame('pricelist_tj', $config->supplier->fresh()->activePriceListImport->source_type->value);
        $this->assertSame('completed', $download->fresh()->status);
    }

    #[TestWith(['2026-11-10T10:00:00+05:00', '2026-11-09T10:00:00+05:00', null])]
    #[TestWith(['2026-11-10T10:00:00+05:00', '2026-11-11T10:00:00+05:00', 'older_than_active'])]
    public function test_freshness_is_monitoring_but_never_allows_an_older_cutover(string $candidate, string $activeDate, ?string $reason): void
    {
        [$config, $partner] = $this->configured();
        $active = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Completed, 'source_published_at' => $activeDate]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        $import = $this->candidate($config, $partner, ['source_published_at' => $candidate]);
        $this->assertSame($reason, app(SupplierSourcePolicy::class)->reason($import));
    }

    #[TestWith(['day', 'day', '2026-11-12 00:00:00', '2026-11-12 00:00:00', 0])]
    #[TestWith(['day', 'second', '2026-11-12 00:00:00', '2026-11-12 12:00:00', null])]
    #[TestWith(['minute', 'second', '2026-11-12 12:00:00', '2026-11-12 12:00:30', null])]
    #[TestWith(['day', 'second', '2026-11-12 00:00:00', '2026-11-13 00:00:00', -1])]
    public function test_precision_uses_non_overlapping_intervals(string $precision, string $otherPrecision, string $date, string $other, ?int $expected): void
    {
        $this->assertSame($expected, app(NewestSupplierCandidates::class)->compare(CarbonImmutable::parse($date), $precision, CarbonImmutable::parse($other), $otherPrecision));
    }

    public function test_equal_dates_use_priority_and_same_source_retains_active_version(): void
    {
        [$config, $partner] = $this->configured();
        $active = $this->candidate($config, $partner, ['source_type' => 'email', 'status' => PriceListImportStatus::Completed]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        $import = $this->candidate($config, $partner);
        $policy = app(SupplierSourcePolicy::class);
        $this->assertSame('equal_date_lower_priority', $policy->reason($import));
        $active->update(['source_type' => 'pricelist_tj']);
        $this->assertSame('same_source_equal_date', $policy->reason($import));
        $active->update(['source_fingerprint' => 'same']);
        $import->update(['source_published_at' => now(), 'source_fingerprint' => 'same']);
        $this->assertSame('unchanged_content', $policy->reason($import));
    }

    public function test_unknown_and_future_candidates_do_not_block_reliably_dated_candidates(): void
    {
        [$config, $partner] = $this->configured();
        $unknown = $this->candidate($config, $partner, ['source_type' => 'email', 'source_published_at' => null, 'source_date_precision' => null]);
        $import = $this->candidate($config, $partner);
        $policy = app(SupplierSourcePolicy::class);
        $this->assertSame('review_missing_date', $policy->reason($unknown));
        $this->assertNull($policy->reason($import));
        $unknown->update(['source_published_at' => now()->addDay(), 'source_date_precision' => 'second']);
        $this->assertSame('review_future_date', $policy->reason($unknown));
        $this->assertNull($policy->reason($import));
    }

    public function test_activation_rechecks_newer_mailbox_arrivals_and_configuration(): void
    {
        [$config, $partner] = $this->configured();
        $import = $this->candidate($config, $partner);
        $this->assertNull(app(SupplierSourcePolicy::class)->reason($import));
        $mail = $this->candidate($config, $partner, ['source_type' => 'email', 'source_published_at' => now()]);
        $this->assertSame('newer_candidate_pending', app(SupplierSourcePolicy::class)->reason($import));
        $mail->update(['error_rows' => 10]);
        $this->assertNull(app(SupplierSourcePolicy::class)->reason($import));
        $config->increment('version');
        $this->assertSame('configuration_changed', app(SupplierSourcePolicy::class)->reason($import));
    }

    public function test_mailbox_backlog_wait_is_bounded_by_fifteen_minutes(): void
    {
        [$config] = $this->configured();
        ExternalServiceState::whereKey('email')->update(['last_success_at' => null, 'status' => 'failed']);
        $this->fakeExternal(now()->subHour()->toIso8601String());
        $run = $this->process($config);
        $this->assertSame('waiting_mailbox', $run->fresh()->status);
        Http::assertNothingSent();
        $this->travel(15)->minutes();
        app(SupplierSynchronizationService::class)->process($run->fresh());
        $this->assertSame('downloading', $run->fresh()->status);
        $this->assertSame(1, ExternalDownload::count());
    }

    public function test_pending_mail_receipt_rechecks_eligibility_at_activation(): void
    {
        [$config, $partner] = $this->configured();
        GmailImportReceipt::factory()->create(['supplier_organization_id' => $config->supplier_organization_id, 'status' => 'pending', 'price_list_import_id' => null]);
        $candidate = $this->candidate($config, $partner);
        $this->assertSame('mailbox_processing', app(SupplierSourcePolicy::class)->reason($candidate));
        $this->travel(16)->minutes();
        $this->assertNull(app(SupplierSourcePolicy::class)->reason($candidate));
    }

    public function test_shared_directory_caches_missing_results_for_one_hour(): void
    {
        [$config, $partner] = $this->configured();
        $this->fakeHttp(['pricelist-tj.com/api/companies?*' => Http::response(['companies' => [], 'pagination' => ['page' => 1, 'total' => 0, 'totalPages' => 0]])]);
        $this->seedSession();
        $directory = app(ExternalSupplierDirectory::class);
        $directory->availability('pricelist_tj');
        $directory->availability('pricelist_tj');
        $this->assertFalse(ExternalAvailability::firstOrFail()->available);
        Http::assertSentCount(1);
        $this->travel(61)->minutes();
        $directory->availability('pricelist_tj');
        Http::assertSentCount(2);
    }

    public function test_page_replay_is_idempotent_and_global_slot_is_retained_between_pages(): void
    {
        [$config] = $this->configured();
        $date = now()->subHour()->toIso8601String();
        $this->fakeExternal($date, pages: 2);
        $run = $this->process($config);
        $download = ExternalDownload::firstOrFail();
        $downloads = app(ExternalPriceDownload::class);
        $downloads->process($download->id);
        $this->assertSame(2, $download->fresh()->next_page);
        $this->assertSame('download:'.$download->id, DB::table('external_download_leases')->first()->owner);
        $this->assertSame(0, PriceListImport::count());
        $downloads->process($download->id);
        $downloads->process($download->id);
        $this->assertSame(1, PriceListImport::count());
        $this->assertSame(0, DB::table('external_download_pages')->count());
        $this->assertNull(DB::table('external_download_leases')->first()->owner);
        Http::assertSentCount(3);
    }

    #[TestWith(['catalog', 'failed'])]
    #[TestWith(['configuration', 'cancelled'])]
    public function test_changed_catalog_or_configuration_never_creates_a_partial_import(string $change, string $status): void
    {
        [$config] = $this->configured();
        $date = now()->subHour()->toIso8601String();
        $this->fakeExternal($date, pages: 2);
        $this->process($config);
        $download = ExternalDownload::firstOrFail();
        app(ExternalPriceDownload::class)->process($download->id);
        if ($change === 'configuration') {
            $config->increment('version');
        } else {
            $this->fakeHttp(['pricelist-tj.com/api/companies/supplier?*' => Http::response($this->page(now()->toIso8601String(), 2, 2))]);
        }
        app(ExternalPriceDownload::class)->process($download->id);
        $this->assertSame($status, $download->fresh()->status);
        $this->assertSame(0, PriceListImport::count());
    }

    public function test_global_lease_serializes_services_and_fences_abandoned_workers(): void
    {
        $lease = app(ExternalDownloadLease::class);
        $first = $lease->claim('icorp');
        try {
            $lease->claim('pricelist_tj');
            $this->fail('Only one supplier may own the global slot.');
        } catch (ExternalServiceUnavailable $exception) {
            $this->assertSame('download_busy', $exception->reason);
        }
        $this->travel(4)->minutes();
        $second = $lease->claim('pricelist_tj');
        $lease->release('icorp', $first);
        $this->assertSame('pricelist_tj', DB::table('external_download_leases')->first()->owner);
        $this->expectException(ExternalServiceUnavailable::class);
        $lease->assertOwned('icorp', $first);
    }

    public function test_cli_client_obeys_global_contention_without_http_requests(): void
    {
        app(ExternalDownloadLease::class)->claim('other-service');
        try {
            app(PriceListTjClient::class)->prices('supplier');
            $this->fail('Direct clients must use the same lease.');
        } catch (ExternalServiceUnavailable $exception) {
            $this->assertSame('download_busy', $exception->reason);
        }
        Http::assertNothingSent();
    }

    public function test_transient_failures_retry_three_times_then_cool_down_and_release_slot(): void
    {
        [$config] = $this->configured();
        $this->fakeExternal(now()->subHour()->toIso8601String());
        $this->process($config);
        $this->fakeHttp(['pricelist-tj.com/api/companies/supplier?*' => Http::response([], 503)]);
        $download = ExternalDownload::firstOrFail();
        foreach ([300, 600, 1200, 21600] as $index => $delay) {
            app(ExternalPriceDownload::class)->process($download->id);
            $download->refresh();
            $this->assertSame($index + 1, $download->retry_count);
            $seconds = (int) now()->diffInSeconds($download->next_retry_at);
            $this->assertGreaterThanOrEqual($delay, $seconds);
            $this->assertLessThanOrEqual((int) ($delay * 1.2), $seconds);
            $this->assertNull(DB::table('external_download_leases')->first()->owner);
            if ($index < 3) {
                app(ExternalPriceDownload::class)->process($download->id);
                $this->assertSame($index + 1, $download->fresh()->retry_count);
                $this->travelTo($download->next_retry_at);
            }
        }
        $this->assertSame('cooldown', $download->status);
        $this->artisan('suppliers:sync')->assertSuccessful();
        $this->assertSame(4, $download->fresh()->retry_count);
    }

    #[TestWith(['120', 3600])]
    #[TestWith(['7200', 7200])]
    public function test_429_sets_durable_service_wide_cooldown(string $retryAfter, int $seconds): void
    {
        [$config] = $this->configured();
        $this->fakeExternal(now()->subHour()->toIso8601String());
        $this->process($config);
        $this->fakeHttp(['pricelist-tj.com/api/companies/supplier?*' => Http::response([], 429, ['Retry-After' => $retryAfter])]);
        app(ExternalPriceDownload::class)->process(ExternalDownload::firstOrFail()->id);
        $state = ExternalServiceState::findOrFail('pricelist_tj');
        $this->assertTrue($state->cooldown_until->equalTo(now()->addSeconds($seconds)));
        $this->assertSame(1, ExternalServiceIncident::count());
        $this->expectException(ExternalServiceUnavailable::class);
        app(ExternalServiceControl::class)->assertAvailable('pricelist_tj');
    }

    #[TestWith([401, '{}', 'authentication'])]
    #[TestWith([403, '{}', 'permission'])]
    #[TestWith([200, '<html>CAPTCHA</html>', 'challenge'])]
    public function test_access_challenges_pause_without_automatic_login_and_alert_once(int $status, string $body, string $reason): void
    {
        [$config] = $this->configured();
        $this->fakeExternal(now()->subHour()->toIso8601String());
        $this->process($config);
        $this->fakeHttp(['pricelist-tj.com/api/companies/supplier?*' => Http::response($body, $status)]);
        $download = ExternalDownload::firstOrFail();
        app(ExternalPriceDownload::class)->process($download->id);
        app(ExternalServiceControl::class)->pause('pricelist_tj', $reason);
        $this->assertSame('paused', ExternalServiceState::find('pricelist_tj')->status);
        $this->assertSame(1, ExternalServiceIncident::count());
        Queue::assertPushed(NotifyExternalServiceIncident::class, 1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/auth/login'));
    }

    public function test_pacing_is_shared_between_client_instances_and_authentication(): void
    {
        $this->fakeHttp(['*' => Http::response(['token' => 'token', 'companies' => [], 'pagination' => ['page' => 1, 'total' => 0, 'totalPages' => 0]])]);
        config(['external-prices.pricelist_tj.phone' => '927430570', 'external-prices.pricelist_tj.password' => 'test']);
        (new PriceListTjClient)->connect();
        (new PriceListTjClient)->partners();
        Sleep::assertSleptTimes(1);
        $this->assertTrue(ExternalServiceState::find('pricelist_tj')->next_request_at->equalTo(now()->addSeconds(5)));
    }

    public function test_notification_delivery_is_deduplicated_and_does_not_resume_service(): void
    {
        Mail::shouldReceive('raw')->once()->with(\Mockery::type('string'), \Mockery::type(\Closure::class));
        User::factory()->admin()->create(['email' => 'active@example.test']);
        User::factory()->admin()->create(['email' => 'blocked@example.test', 'is_blocked' => true]);
        app(ExternalServiceControl::class)->pause('pricelist_tj', 'authentication');
        $incident = ExternalServiceIncident::firstOrFail();
        $job = new NotifyExternalServiceIncident($incident->id);
        $job->handle();
        $job->handle();
        $this->assertSame(['active@example.test'], $incident->fresh()->delivered_to);
        $this->assertNotNull($incident->fresh()->notified_at);
        $this->assertSame('paused', ExternalServiceState::find('pricelist_tj')->status);
    }

    public function test_opt_in_is_explicit_and_settings_remain_authorized_and_audited(): void
    {
        [$config] = $this->configured();
        $data = $config->only(['selection_mode', 'sources', 'automatic_enabled', 'paused', 'freshness_hours', 'fetch_interval_minutes']) + ['expected_version' => $config->version];
        $data['fetch_interval_minutes'] = 15;
        $this->actingAs(User::factory()->create())->putJson(route('admin.supplier-sync.update', $config->supplier), $data)->assertForbidden();
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]))->putJson(route('admin.supplier-sync.update', $config->supplier), $data)->assertRedirect();
        $this->assertSame(60, $config->fresh()->fetch_interval_minutes);
        $this->assertSame(['email', 'pricelist_tj'], $config->fresh()->enabledSources());
        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_sync.configured']);
        $this->get(route('admin.supplier-sync.show', $config->supplier))->assertOk()->assertSee('Фоновая загрузка и доступность');
    }

    #[TestWith(['upload_time', 'mail.csv', null])]
    #[TestWith(['filename', 'prices-2026-11-12.csv', '2026-11-12'])]
    #[TestWith(['filename', 'prices-invalid.csv', null])]
    #[TestWith(['filename', 'prices-20261112.csv', '2026-11-12'])]
    public function test_mailbox_publication_never_falls_back_to_receipt_or_upload_time(string $origin, string $filename, ?string $expected): void
    {
        [$config] = $this->configured();
        $profile = $config->supplier->importProfile;
        $configuration = $profile->configuration;
        $configuration['inventory'] = ['source' => $origin, 'pattern' => '/prices-(\d{4}-?\d{2}-?\d{2})/', 'group' => 1, 'formats' => ['Y-m-d', 'Ymd']];
        $profile->update(['file_type' => 'csv', 'configuration' => $configuration]);
        Storage::disk('local')->put('mail.csv', "name,price\nАспирин,12.5\n");
        $file = new StoredImportFile('local', 'mail.csv', $filename, 'text/csv', Storage::disk('local')->size('mail.csv'), hash_file('sha256', Storage::disk('local')->path('mail.csv')));
        $import = app(SupplierPriceListIngestor::class)->ingest($config->supplier, $file,
            new IngestionContext(PriceListImportSource::Email, receivedAt: CarbonImmutable::now()));
        $import->refresh();
        $this->assertSame($expected, $import->source_published_at?->toDateString());
        $this->assertSame($expected ? PriceListImportStatus::Completed : PriceListImportStatus::Preview, $import->status);
        if (! $expected) {
            $this->assertSame('review_missing_date', app(SupplierSourcePolicy::class)->reason($import));
        }
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_new_mode_falls_back_after_validation_or_extraction_failure(bool $validation): void
    {
        [$config, $partner] = $this->configured();
        $this->fakeExternal(now()->subHour()->toIso8601String());
        app(ExternalSupplierDirectory::class)->availability('pricelist_tj');
        $profile = $config->supplier->importProfile;
        $configuration = $profile->configuration;
        $configuration['inventory'] = ['source' => 'filename', 'pattern' => '/prices-(\d{4}-\d{2}-\d{2})/', 'group' => 1, 'formats' => ['Y-m-d']];
        $profile->update(['file_type' => 'csv', 'configuration' => $configuration]);
        Storage::disk('local')->put('mail.csv', "name,price\nАспирин,12.5\n");
        $file = new StoredImportFile('local', 'mail.csv', 'prices-2026-11-12.csv', 'text/csv', Storage::disk('local')->size('mail.csv'), hash_file('sha256', Storage::disk('local')->path('mail.csv')));
        $mail = app(SupplierPriceListIngestor::class)->ingest($config->supplier, $file, new IngestionContext(PriceListImportSource::Email));
        $this->assertSame(PriceListImportStatus::Preview, $mail->fresh()->status);
        if ($validation) {
            $partner->update(['profile_configuration' => ExternalPriceWorkbook::profile(['automatic' => ['minimum_valid_rows' => 10]])]);
        } else {
            $this->fakeHttp(['pricelist-tj.com/api/companies/supplier?*' => Http::response(['restricted' => false, 'company' => ['id' => 'different'], 'priceItems' => [], 'pagination' => ['page' => 1, 'total' => 1, 'totalPages' => 1]])]);
        }
        $run = $this->process($config);
        app(ExternalPriceDownload::class)->process(ExternalDownload::firstOrFail()->id);
        app(SupplierSynchronizationService::class)->process($run->fresh());
        $this->assertSame($mail->id, $config->supplier->fresh()->active_price_list_import_id);
        $this->assertSame(PriceListImportStatus::Completed, $mail->fresh()->status);
    }

    public function test_directory_scan_is_resumable_and_never_exposes_partial_availability(): void
    {
        $this->configured();
        $this->seedSession();
        Queue::fake([RefreshExternalSupplierDirectory::class]);
        $this->fakeHttp(['pricelist-tj.com/api/companies?*' => fn ($request) => Http::response([
            'companies' => [['id' => 'supplier-'.$request['page'], 'name' => 'Поставщик', 'verified' => true]],
            'pagination' => ['page' => (int) $request['page'], 'total' => 2, 'totalPages' => 2],
        ])]);
        $directory = app(ExternalSupplierDirectory::class);
        $directory->refreshAvailabilityPage('pricelist_tj');
        $state = ExternalServiceState::findOrFail('pricelist_tj');
        $this->assertNull($state->directory);
        $this->assertSame(2, $state->directory_progress['next_page']);
        $this->assertSame(0, ExternalAvailability::count());
        $directory->refreshAvailabilityPage('pricelist_tj');
        $directory->refreshAvailabilityPage('pricelist_tj');
        $this->assertCount(2, $state->fresh()->directory);
        $this->assertNull($state->fresh()->directory_progress);
        Http::assertSentCount(2);
    }

    public function test_unknown_external_date_can_be_confirmed_with_authorization_and_audit(): void
    {
        [$config] = $this->configured();
        $this->fakeExternal(null);
        app(ExternalSupplierDirectory::class)->availability('pricelist_tj');
        $observation = ExternalAvailability::firstOrFail();
        $data = ['version_key' => $observation->version_key, 'published_at' => '2026-11-12T12:00', 'evidence' => 'Подтверждено поставщиком'];
        $this->actingAs(User::factory()->create())->postJson(route('admin.supplier-sync.availability-date', $observation), $data)->assertForbidden();
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]))->post(route('admin.supplier-sync.availability-date', $observation), $data)->assertRedirect();
        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_sync.availability_date_confirmed', 'subject_id' => $observation->id]);
        $this->process($config);
        app(ExternalPriceDownload::class)->process(ExternalDownload::firstOrFail()->id);
        $import = PriceListImport::firstOrFail();
        $this->assertSame(PriceListImportStatus::Completed, $import->status);
        $this->assertSame('2026-11-12 12:00:00', $import->source_published_at->format('Y-m-d H:i:s'));
        $this->assertSame('Подтверждено поставщиком', $import->source_metadata['date_evidence']);
    }

    public function test_administrator_recovery_resolves_incident_but_notifications_do_not(): void
    {
        app(ExternalServiceControl::class)->pause('pricelist_tj', 'authentication');
        config(['external-prices.pricelist_tj.phone' => '927430570', 'external-prices.pricelist_tj.password' => 'test']);
        $this->fakeHttp(['pricelist-tj.com/api/auth/login' => Http::response(['token' => 'recovered'])]);
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]))->post(route('admin.supplier-sync.connect', 'pricelist_tj'))->assertRedirect();
        $this->assertSame('ready', ExternalServiceState::find('pricelist_tj')->status);
        $this->assertNotNull(ExternalServiceIncident::firstOrFail()->resolved_at);
        $this->assertDatabaseHas('audit_events', ['event' => 'supplier_sync.connection_requested']);
    }

    public function test_failed_notification_is_visible_and_retryable_without_repeated_successful_delivery(): void
    {
        User::factory()->admin()->create(['email' => 'admin@example.test']);
        app(ExternalServiceControl::class)->pause('pricelist_tj', 'authentication');
        $incident = ExternalServiceIncident::firstOrFail();
        Mail::shouldReceive('getDefaultDriver')->andReturn('array');
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('Transport failure'));
        try {
            (new NotifyExternalServiceIncident($incident->id))->handle();
            $this->fail('Delivery failure must remain retryable.');
        } catch (\RuntimeException) {
            $this->assertSame('delivery_failed', $incident->fresh()->notification_error);
            $this->assertNull($incident->fresh()->notified_at);
        }
        Mail::fake();
        (new NotifyExternalServiceIncident($incident->id))->handle();
        $this->assertNotNull($incident->fresh()->notified_at);
        $this->assertNull($incident->fresh()->notification_error);
        $this->assertSame('paused', ExternalServiceState::find('pricelist_tj')->status);
    }

    public function test_activation_uses_the_supplier_locked_inside_the_transaction(): void
    {
        [$config, $partner] = $this->configured();
        $candidate = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Committing]);
        $candidate->load('supplier');
        $active = $this->candidate($config, $partner, ['status' => PriceListImportStatus::Completed, 'source_published_at' => now()]);
        $config->supplier->update(['active_price_list_import_id' => $active->id]);
        try {
            app(ImportActivator::class)->commit($candidate);
            $this->fail('The locked active list must be compared again.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('older_than_active', $exception->getMessage());
        }
        $this->assertSame($active->id, $config->supplier->fresh()->active_price_list_import_id);
    }

    public function test_completed_pages_recover_after_a_crash_before_import_creation(): void
    {
        [$config] = $this->configured();
        $date = now()->subHour()->toIso8601String();
        $this->fakeExternal($date);
        $this->process($config);
        $download = ExternalDownload::firstOrFail();
        $page = $this->page($date, 1, 1);
        $download->update(['status' => 'downloading', 'next_page' => 2, 'received_rows' => 1, 'total_rows' => 1, 'total_pages' => 1, 'company' => $page['company']]);
        DB::table('external_download_pages')->insert(['external_download_id' => $download->id, 'page' => 1, 'items' => json_encode([['external_id' => 'row-1', 'name' => 'Лекарство', 'price' => 10]])]);
        app(ExternalPriceDownload::class)->process($download->id);
        $this->assertSame('completed', $download->fresh()->status);
        $this->assertSame(1, PriceListImport::count());
        Http::assertSentCount(1);
    }

    public function test_repeated_dispatch_claims_a_remote_version_once(): void
    {
        [$config] = $this->configured();
        $this->fakeExternal(now()->subHour()->toIso8601String());
        $run = $this->process($config);
        $again = app(SupplierSynchronizationService::class)->request($config->supplier, 'automatic');
        $this->assertSame($run->id, $again->id);
        app(SupplierSynchronizationService::class)->process($run->fresh());
        $this->assertSame(1, ExternalDownload::count());
        app(ExternalPriceDownload::class)->process(ExternalDownload::firstOrFail()->id);
        $this->process($config);
        $this->assertSame(1, ExternalDownload::count());
        Http::assertSentCount(2);
    }

    public function test_a_date_cell_outside_the_product_columns_preserves_its_precision(): void
    {
        [$config] = $this->configured();
        $profile = $config->supplier->importProfile;
        $configuration = $profile->configuration;
        $configuration['inventory'] = ['source' => 'cell', 'cell' => 'Z1', 'formats' => ['d.m.Y H:i']];
        $profile->update(['configuration' => $configuration]);
        $file = app(ExternalPriceWorkbook::class)->store([['external_id' => 'one', 'name' => 'Лекарство', 'price' => 10]]);
        $book = IOFactory::load(Storage::disk('local')->path($file->path));
        $book->getActiveSheet()->setCellValue('Z1', '12.11.2026 09:30');
        (new Xlsx($book))->save(Storage::disk('local')->path($file->path));
        $book->disconnectWorksheets();
        $import = app(SupplierPriceListIngestor::class)->ingest($config->supplier, $file, new IngestionContext(PriceListImportSource::Email));
        $this->assertSame('2026-11-12 09:30:00', $import->fresh()->source_published_at->format('Y-m-d H:i:s'));
        $this->assertSame('minute', $import->fresh()->source_date_precision);
    }

    public function test_stale_page_workers_cannot_write_or_release_a_newer_lease(): void
    {
        [$config] = $this->configured();
        $date = now()->subHour()->toIso8601String();
        $this->fakeExternal($date);
        $this->process($config);
        $download = ExternalDownload::firstOrFail();
        $this->fakeHttp(['pricelist-tj.com/api/companies/supplier?*' => function () use ($download, $date) {
            $download->update(['worker_token' => 'new-worker', 'worker_expires_at' => now()->addMinutes(2)]);
            app(ExternalDownloadLease::class)->claim('download:'.$download->id);

            return Http::response($this->page($date, 1, 1));
        }]);
        app(ExternalPriceDownload::class)->process($download->id);
        $this->assertSame(0, DB::table('external_download_pages')->count());
        $this->assertSame(0, PriceListImport::count());
        $this->assertSame('new-worker', $download->fresh()->worker_token);
        $this->assertSame('download:'.$download->id, DB::table('external_download_leases')->first()->owner);
    }

    public function test_configuration_changes_during_a_request_are_fenced_before_staging(): void
    {
        [$config] = $this->configured();
        $date = now()->subHour()->toIso8601String();
        $this->fakeExternal($date);
        $this->process($config);
        $this->fakeHttp(['pricelist-tj.com/api/companies/supplier?*' => function () use ($config, $date) {
            $config->increment('version');

            return Http::response($this->page($date, 1, 1));
        }]);
        $download = ExternalDownload::firstOrFail();
        app(ExternalPriceDownload::class)->process($download->id);
        $this->assertSame('cancelled', $download->fresh()->status);
        $this->assertSame(0, DB::table('external_download_pages')->count());
        $this->assertSame(0, PriceListImport::count());
        $this->process($config->fresh());
        $this->process($config->fresh());
        $this->assertSame(1, ExternalDownload::count());
        $this->assertSame($config->version, $download->fresh()->snapshot['configuration_version']);
    }

    public function test_global_pacing_applies_between_services_and_honors_stricter_limits(): void
    {
        Sleep::fake(syncWithCarbon: true);
        config(['external-prices.icorp.request_interval_seconds' => 10]);
        $times = [];
        $this->fakeHttp(['*' => function () use (&$times) {
            $times[] = now()->getTimestamp();

            return Http::response(['ok' => true]);
        }]);
        $control = app(ExternalServiceControl::class);
        $control->request('icorp', fn () => Http::get('https://icorp.tech/first'));
        $control->request('pricelist_tj', fn () => Http::get('https://pricelist-tj.com/second'));
        $control->request('icorp', fn () => Http::get('https://icorp.tech/third'));
        $this->assertGreaterThanOrEqual(5, $times[1] - $times[0]);
        $this->assertGreaterThanOrEqual(5, $times[2] - $times[1]);
        $this->assertGreaterThanOrEqual(10, $times[2] - $times[0]);
    }

    public function test_manual_renewal_can_activate_the_newest_mailbox_preview_while_automatic_loading_is_paused(): void
    {
        [$config] = $this->configured();
        $sources = $config->sources;
        $sources['pricelist_tj']['enabled'] = false;
        $config->update(['automatic_enabled' => false, 'paused' => true, 'sources' => $sources]);
        $profile = $config->supplier->importProfile;
        $configuration = $profile->configuration;
        $configuration['inventory'] = ['source' => 'filename', 'pattern' => '/prices-(\d{4}-\d{2}-\d{2})/', 'group' => 1, 'formats' => ['Y-m-d']];
        $profile->update(['file_type' => 'csv', 'configuration' => $configuration]);
        Storage::disk('local')->put('mail.csv', "name,price\nАспирин,12.5\n");
        $file = new StoredImportFile('local', 'mail.csv', 'prices-2026-11-12.csv', 'text/csv', Storage::disk('local')->size('mail.csv'), hash_file('sha256', Storage::disk('local')->path('mail.csv')));
        $mail = app(SupplierPriceListIngestor::class)->ingest($config->supplier, $file, new IngestionContext(PriceListImportSource::Email));
        $this->assertSame(PriceListImportStatus::Preview, $mail->fresh()->status);
        $service = app(SupplierSynchronizationService::class);
        $run = $service->request($config->supplier, 'manual');
        $service->process($run);
        $this->assertSame($mail->id, $config->supplier->fresh()->active_price_list_import_id);
        $this->assertSame('manual', $mail->fresh()->source_metadata['trigger']);
        Http::assertNothingSent();
    }

    private function fakeHttp(array $responses): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    private function configured(): array
    {
        $supplier = Organization::factory()->wholesaler()->create();
        SupplierImportProfile::factory()->for($supplier, 'supplier')->create(['is_active' => true]);
        $sources = SupplierSynchronization::defaultSources();
        $sources['icorp']['enabled'] = false;
        $config = SupplierSynchronization::factory()->for($supplier, 'supplier')->create(['selection_mode' => 'newest_available', 'selected_source' => null, 'sources' => $sources, 'automatic_enabled' => true, 'settings_confirmed' => true, 'paused' => false, 'fetch_interval_minutes' => 60]);
        $partner = ExternalSupplierPartner::factory()->create(['supplier_organization_id' => $supplier->id, 'source' => 'pricelist_tj', 'external_id' => 'supplier', 'profile_active' => true, 'profile_configuration' => ExternalPriceWorkbook::profile()]);
        ExternalServiceState::whereKey('email')->update(['last_success_at' => now()]);

        return [$config->fresh(), $partner->fresh()];
    }

    private function candidate(SupplierSynchronization $config, ExternalSupplierPartner $partner, array $overrides = []): PriceListImport
    {
        $attributes = array_replace(['source_type' => 'pricelist_tj', 'status' => PriceListImportStatus::Preview, 'source_published_at' => now()->subHour(), 'source_date_precision' => 'second',
            'valid_rows' => 1, 'error_rows' => 0, 'total_rows' => 1, 'profile_snapshot' => ExternalPriceWorkbook::profile()], $overrides);
        $attributes['source_metadata'] = ['configuration_version' => $config->version, 'partner_id' => $partner->id, 'external_id' => $partner->external_id, 'profile_version' => $partner->profile_version,
            'publication_origin' => 'filename', 'profile_hash' => SupplierSourcePolicy::profileHash($config->supplier->importProfile->configuration)];

        return PriceListImport::factory()->for($config->supplier, 'supplier')->create($attributes);
    }

    private function process(SupplierSynchronization $config): SupplierSyncRun
    {
        $service = app(SupplierSynchronizationService::class);
        $run = $service->request($config->supplier, 'automatic');
        $service->process($run);

        return $run->fresh();
    }

    private function seedSession(): void
    {
        config(['external-prices.pricelist_tj.phone' => '927430570']);
        Storage::disk('local')->put('external-prices/sessions/pricelist-tj-'.hash('sha256', '927430570').'.encrypted', encrypt('token', serialize: false));
    }

    private function fakeExternal(?string $date, int $pages = 1): void
    {
        $this->seedSession();
        $this->fakeHttp([
            'pricelist-tj.com/api/companies?*' => Http::response(['companies' => [['id' => 'supplier', 'name' => 'Поставщик', 'verified' => true, 'lastPriceUpdate' => $date]], 'pagination' => ['page' => 1, 'total' => 1, 'totalPages' => 1]]),
            'pricelist-tj.com/api/companies/supplier?*' => fn ($request) => Http::response($this->page($date, (int) $request['page'], $pages)),
        ]);
    }

    private function page(?string $date, int $number, int $pages): array
    {
        return ['company' => ['id' => 'supplier', 'name' => 'Поставщик', 'lastPriceUpdate' => $date], 'restricted' => false,
            'priceItems' => [['id' => 'row-'.$number, 'companyId' => 'supplier', 'medicine' => 'Лекарство '.$number, 'price' => 10]], 'myDiscount' => null,
            'pagination' => ['page' => $number, 'total' => $pages, 'totalPages' => $pages]];
    }
}
