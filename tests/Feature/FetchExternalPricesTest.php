<?php

namespace Tests\Feature;

use App\Jobs\SynchronizeSupplier;
use App\Models\ExternalSupplierPartner;
use App\Models\SupplierSynchronization;
use App\Services\PriceListTjClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class FetchExternalPricesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_requires_the_exclusion_list_before_making_any_external_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('external-prices:fetch', ['source' => 'pricelist_tj'])
            ->expectsOutputToContain('Provide --exclude')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_discovery_saves_partner_metadata_without_requesting_prices(): void
    {
        $this->configure();
        $this->fakeDirectory();

        $this->artisan('external-prices:fetch', ['source' => 'pricelist_tj', '--discover' => true])
            ->expectsOutputToContain('3 partners discovered')->assertSuccessful();

        $files = Storage::disk('local')->allFiles('external-prices/runs');
        $this->assertCount(1, $files);
        $this->assertCount(3, json_decode(Storage::disk('local')->get($files[0]), true));
        Http::assertSentCount(1);
    }

    public function test_configured_suppliers_join_system_queue_without_downloading_or_creating_profiles(): void
    {
        $this->configure();
        Queue::fake([SynchronizeSupplier::class]);
        $configuration = SupplierSynchronization::factory()->create(['automatic_enabled' => true, 'settings_confirmed' => true]);
        $partner = ExternalSupplierPartner::factory()->create(['source' => 'pricelist_tj',
            'supplier_organization_id' => $configuration->supplier_organization_id, 'profile_active' => true]);
        $excluded = $this->exclusionFile([]);
        try {
            $this->artisan('external-prices:fetch', ['source' => 'pricelist_tj', '--exclude' => $excluded])
                ->expectsOutputToContain('1 supplier imports queued')->assertSuccessful();
            $this->assertDatabaseHas('external_import_queue_items', ['lane_id' => 'system', 'requested_by' => null]);
            $this->assertDatabaseCount('supplier_import_profiles', 0);
            $this->assertDatabaseCount('price_list_imports', 0);
            $this->assertSame([], Storage::disk('local')->allFiles('external-prices/runs'));
            Http::assertNothingSent();
            Queue::assertPushed(SynchronizeSupplier::class);
        } finally {
            unlink($excluded);
        }
    }

    public function test_does_not_save_a_partial_supplier_list_after_pagination_failure(): void
    {
        $this->configure();
        $this->fakeDirectory(failPrices: true);
        try {
            app(PriceListTjClient::class)->prices('new');
            $this->fail('An incomplete supplier list was returned.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('row count', $exception->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('external-prices/runs'));
        Http::assertSentCount(1);
    }

    public function test_rejects_malformed_exclusions_without_connecting_to_the_service(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $path = tempnam(sys_get_temp_dir(), 'external-invalid-');
        file_put_contents($path, '{}');

        try {
            $this->artisan('external-prices:fetch', ['source' => 'pricelist_tj', '--exclude' => $path])
                ->expectsOutputToContain('exclusion list is invalid')->assertFailed();

            Http::assertNothingSent();
        } finally {
            unlink($path);
        }
    }

    private function configure(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['external-prices.pricelist_tj.phone' => '927430570', 'external-prices.pricelist_tj.password' => 'test-password']);
        Storage::disk('local')->put('external-prices/sessions/pricelist-tj-'.hash('sha256', '927430570').'.encrypted', Crypt::encryptString('private-token'));
    }

    private function fakeDirectory(bool $failPrices = false): void
    {
        Http::fake([
            'pricelist-tj.com/api/auth/login' => Http::response(['token' => 'private-token']),
            'pricelist-tj.com/api/companies?*' => Http::response([
                'companies' => [['id' => 'active', 'name' => 'Активный', 'verified' => true], ['id' => 'sender', 'name' => 'Отправляет', 'verified' => true], ['id' => 'new', 'name' => 'Новый', 'verified' => true]],
                'pagination' => ['page' => 1, 'total' => 3, 'totalPages' => 1],
            ]),
            'pricelist-tj.com/api/companies/new?*' => Http::response([
                'company' => ['id' => 'new', 'name' => 'Новый'], 'restricted' => false,
                'priceItems' => [['id' => 'row', 'companyId' => 'new', 'medicine' => 'Товар', 'price' => 10]],
                'pagination' => ['page' => 1, 'total' => $failPrices ? 2 : 1, 'totalPages' => 1],
            ]),
        ]);
    }

    /** @param list<array<string, mixed>> $suppliers */
    private function exclusionFile(array $suppliers): string
    {
        $path = tempnam(sys_get_temp_dir(), 'external-exclusions-');
        file_put_contents($path, json_encode($suppliers, JSON_THROW_ON_ERROR));

        return $path;
    }
}
