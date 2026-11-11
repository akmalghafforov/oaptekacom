<?php

namespace Tests\Feature;

use App\Models\SupplierImportProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FetchExternalPricesTest extends TestCase
{
    use LazilyRefreshDatabase;

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
        Http::assertSentCount(2);
    }

    public function test_downloads_only_eligible_suppliers_and_stages_them_without_creating_profiles(): void
    {
        $this->configure();
        SupplierImportProfile::factory()->create()->supplier->update(['name' => 'Активный']);
        $this->fakeDirectory();
        $excluded = $this->exclusionFile([['name' => 'Отправляет']]);

        try {
            $this->artisan('external-prices:fetch', ['source' => 'pricelist_tj', '--exclude' => $excluded])->assertSuccessful();

            $files = Storage::disk('local')->allFiles('external-prices/runs');
            $reportPath = collect($files)->first(fn (string $path): bool => str_ends_with($path, '/report.json'));
            $report = json_decode(Storage::disk('local')->get($reportPath), true);
            $this->assertSame(['skip', 'skip', 'extracted'], array_column($report, 'outcome'));
            $this->assertSame(['active_import_profile', 'already_sends_price_list', 'supplier_not_registered'], array_column($report, 'reason'));
            $data = json_decode(Storage::disk('local')->get($report[2]['file']), true);
            $this->assertSame('Новый', $data['company']['name']);
            $this->assertSame(10, $data['items'][0]['price']);
            $this->assertDatabaseCount('organizations', 1);
            $this->assertDatabaseCount('supplier_import_profiles', 1);
            $this->assertDatabaseCount('price_list_imports', 0);
            Http::assertSentCount(3);
        } finally {
            unlink($excluded);
        }
    }

    public function test_does_not_save_a_partial_supplier_list_after_pagination_failure(): void
    {
        $this->configure();
        $this->fakeDirectory(failPrices: true);
        $excluded = $this->exclusionFile([['name' => 'Активный'], ['name' => 'Отправляет']]);

        try {
            $this->artisan('external-prices:fetch', ['source' => 'pricelist_tj', '--exclude' => $excluded])->assertFailed();

            $files = Storage::disk('local')->allFiles('external-prices/runs');
            $this->assertCount(2, $files);
            $reportPath = collect($files)->first(fn (string $path): bool => str_ends_with($path, '/report.json'));
            $report = json_decode(Storage::disk('local')->get($reportPath), true);
            $this->assertSame('failed', $report[2]['outcome']);
            $this->assertArrayNotHasKey('file', $report[2]);
        } finally {
            unlink($excluded);
        }
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
