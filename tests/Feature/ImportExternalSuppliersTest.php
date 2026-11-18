<?php

namespace Tests\Feature;

use App\Jobs\DownloadExternalPricePage;
use App\Jobs\NotifyExternalServiceIncident;
use App\Jobs\SynchronizeSupplier;
use App\Models\ExternalDownload;
use App\Models\ExternalListingRun;
use App\Models\ExternalServiceState;
use App\Models\ExternalSupplierPartner;
use App\Models\PriceListImport;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use App\Services\ExternalPriceDownload;
use App\Services\ExternalPriceWorkbook;
use App\Services\ExternalServiceUnavailable;
use App\Services\PriceListTjClient;
use App\Services\RenderedSupplierListing;
use App\Services\SupplierSynchronizationService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ImportExternalSuppliersTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['price-list-imports.daily_versions' => true]);
        $this->freezeTime();
    }

    #[TestWith([true, true])]
    #[TestWith([false, false])]
    public function test_unready_dispatch_does_not_scan_or_queue_work(bool $enabled, bool $paused): void
    {
        Queue::fake();
        config(['price-list-imports.daily_versions' => $enabled]);
        Cache::forever('supplier-imports:dispatch-paused', $paused);

        $this->artisan('suppliers:import-external')->expectsOutputToContain('dispatch is not ready')->assertFailed();

        $this->assertDatabaseCount('external_listing_runs', 0);
        $this->assertDatabaseCount('external_import_queue_items', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_invocations_keep_one_effective_system_import_and_do_not_report_publication(): void
    {
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class]);
        $configuration = $this->readyListing();
        ExternalListingRun::factory()->create(['source' => 'icorp', 'status' => 'completed']);

        $this->artisan('suppliers:import-external')->expectsOutputToContain('queued=1 completed=0')->assertSuccessful();
        $this->artisan('suppliers:import-external')->assertSuccessful();

        $this->assertDatabaseCount('supplier_sync_runs', 1);
        $this->assertDatabaseHas('external_import_queue_items', ['lane_id' => 'system', 'state' => 'queued']);
        $this->assertDatabaseCount('external_import_queue_items', 1);
        $this->assertNull($configuration->supplier->fresh()->successful_price_list_day);
        Queue::assertPushed(SynchronizeSupplier::class);
    }

    public function test_failed_icorp_listing_still_queues_and_processes_pricelist_work(): void
    {
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class]);
        $this->readyListing();
        ExternalServiceState::whereKey('icorp')->update(['status' => 'paused', 'reason' => 'extraction']);
        ExternalListingRun::factory()->create(['source' => 'icorp', 'status' => 'failed', 'reason' => 'extraction']);

        $this->artisan('suppliers:import-external', ['--retry-failed-listings' => true])
            ->expectsOutputToContain('Partial result')->assertFailed();
        app(SupplierSynchronizationService::class)->process(SupplierSyncRun::firstOrFail());

        $this->assertDatabaseHas('external_downloads', ['source' => 'pricelist_tj', 'status' => 'pending']);
        $this->assertDatabaseHas('external_service_states', ['source' => 'icorp', 'status' => 'paused']);
        $this->assertDatabaseCount('audit_events', 0);
        Queue::assertPushed(DownloadExternalPricePage::class);
    }

    public function test_repeating_after_publication_does_not_download_the_same_supplier_day_again(): void
    {
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class]);
        Storage::fake('local');
        $configuration = $this->readyListing();
        ExternalListingRun::factory()->create(['source' => 'icorp', 'status' => 'completed']);
        $this->mock(PriceListTjClient::class)->shouldReceive('pricePage')->once()->andReturn([
            'company' => ['id' => 'supplier', 'name' => 'Listing supplier', 'lastPriceUpdate' => now()->toIso8601String()],
            'items' => [['external_id' => 'one', 'name' => 'Аспирин', 'price' => '10', 'quantity' => '5']],
            'total' => 1, 'total_pages' => 1, 'account_discount' => null,
        ]);

        $this->artisan('suppliers:import-external')->assertSuccessful();
        app(SupplierSynchronizationService::class)->process(SupplierSyncRun::firstOrFail());
        app(ExternalPriceDownload::class)->process(ExternalDownload::firstOrFail()->id);
        $this->artisan('suppliers:import-external')->assertSuccessful();
        app(SupplierSynchronizationService::class)->process(SupplierSyncRun::latest('id')->firstOrFail());

        $this->assertDatabaseCount('external_downloads', 1);
        $this->assertDatabaseCount('price_list_imports', 1);
        $this->assertSame('completed', PriceListImport::firstOrFail()->status->value);
        $this->assertSame(now()->toDateString(), $configuration->supplier->fresh()->successful_price_list_day);
        Queue::assertPushed(SynchronizeSupplier::class);
    }

    #[TestWith(['paused', null, ExternalServiceUnavailable::class])]
    #[TestWith(['disabled', null, ExternalServiceUnavailable::class])]
    #[TestWith(['ready', 'future', ExternalServiceUnavailable::class])]
    #[TestWith(['ready', 'locked', LockTimeoutException::class])]
    public function test_failed_listing_reset_requires_source_availability_and_account_lock(string $status, ?string $guard, string $exception): void
    {
        $run = ExternalListingRun::factory()->create(['status' => 'failed', 'pages' => [1 => ['partial' => true]], 'retry_count' => 3]);
        ExternalServiceState::whereKey('icorp')->update(['status' => $status, 'cooldown_until' => $guard === 'future' ? now()->addHour() : null]);
        $lock = Cache::lock('external-prices:icorp', 140);
        if ($guard === 'locked') {
            $lock->get();
            $this->travelBack();
        }

        try {
            app(RenderedSupplierListing::class)->scan('icorp', true);
            $this->fail('An unavailable account was reset.');
        } catch (ExternalServiceUnavailable|LockTimeoutException $caught) {
            $this->assertInstanceOf($exception, $caught);
        } finally {
            $lock->release();
        }

        $this->assertSame([1 => ['partial' => true]], $run->fresh()->pages);
        $this->assertSame(3, $run->fresh()->retry_count);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_manual_reset_clears_partial_pages_and_audits_without_resetting_download_budgets(): void
    {
        Queue::fake([NotifyExternalServiceIncident::class, SynchronizeSupplier::class, DownloadExternalPricePage::class]);
        Storage::fake('local');
        $configuration = $this->readyListing();
        $synchronizations = app(SupplierSynchronizationService::class);
        $synchronizations->process($synchronizations->request($configuration->supplier));
        $run = ExternalListingRun::factory()->create(['status' => 'failed', 'pages' => [1 => ['partial' => true]], 'retry_count' => 3]);
        $old = ExternalListingRun::factory()->create(['scan_hour' => now()->subHour()->startOfHour(), 'status' => 'failed', 'retry_count' => 3]);
        ExternalServiceState::whereKey('icorp')->update(['status' => 'ready']);
        $download = ExternalDownload::firstOrFail();
        $download->update(['status' => 'failed', 'retry_count' => 4]);

        app(RenderedSupplierListing::class)->scan('icorp', true);

        $this->assertSame([], $run->fresh()->pages);
        $this->assertDatabaseHas('audit_events', ['event' => 'external_listing.retry', 'subject_id' => $run->id]);
        $this->assertSame(3, $old->fresh()->retry_count);
        $this->assertSame(4, $download->fresh()->retry_count);
        $this->assertSame('failed', $download->fresh()->status);
        Queue::assertPushed(NotifyExternalServiceIncident::class);
    }

    public function test_failed_listing_is_preserved_without_manual_retry_and_completed_listing_is_never_reset(): void
    {
        $failed = ExternalListingRun::factory()->create(['source' => 'icorp', 'status' => 'failed', 'retry_count' => 3]);
        $completed = ExternalListingRun::factory()->create(['source' => 'pricelist_tj', 'status' => 'completed', 'pages' => [1 => ['complete' => true]]]);

        app(RenderedSupplierListing::class)->scan('icorp');
        app(RenderedSupplierListing::class)->scan('pricelist_tj', true);

        $this->assertSame(3, $failed->fresh()->retry_count);
        $this->assertSame([1 => ['complete' => true]], $completed->fresh()->pages);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_hourly_operator_command_replaces_scan_and_keeps_minute_recovery(): void
    {
        $events = collect(app(Schedule::class)->events());
        $hourly = $events->first(fn ($event): bool => str_contains($event->command ?? '', 'suppliers:import-external'));
        $recovery = $events->first(fn ($event): bool => str_ends_with($event->command ?? '', 'suppliers:sync'));

        $this->assertNotNull($hourly);
        $this->assertSame('0 * * * *', $hourly->expression);
        $this->assertTrue($hourly->onOneServer);
        $this->assertTrue($hourly->withoutOverlapping);
        $this->assertSame('* * * * *', $recovery->expression);
        $this->assertFalse($events->contains(fn ($event): bool => str_contains($event->command ?? '', 'suppliers:scan-listings')));
    }

    private function readyListing(): SupplierSynchronization
    {
        $configuration = SupplierSynchronization::factory()->create(['automatic_enabled' => true, 'selection_mode' => 'daily_versions']);
        $partner = ExternalSupplierPartner::factory()->create(['supplier_organization_id' => $configuration->supplier_organization_id,
            'source' => 'pricelist_tj', 'profile_active' => true, 'profile_configuration' => ExternalPriceWorkbook::profile()]);
        $run = ExternalListingRun::factory()->create(['source' => 'pricelist_tj', 'pages' => [1 => ['partners' => [
            ['source' => 'pricelist_tj', 'external_id' => $partner->external_id, 'name' => 'Listing supplier', 'phones' => [], 'published_at' => now()->format('d.m.Y').', 10:00', 'available' => true],
        ], 'received' => 1, 'total_pages' => 1]]]);
        app(RenderedSupplierListing::class)->publish($run);

        return $configuration;
    }
}
