<?php

namespace Tests\Feature;

use App\Services\PriceListTjClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class PriceListTjClientTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_discovers_all_pages_and_reuses_an_encrypted_account_session_across_clients(): void
    {
        $this->configure();
        Http::fake([
            'pricelist-tj.com/api/auth/login' => Http::response(['token' => 'private-token']),
            'pricelist-tj.com/api/companies?*' => Http::sequence()
                ->push($this->directory(1, [['id' => 'one', 'name' => 'Первая', 'verified' => true, 'phone' => '+992927430570']]))
                ->push($this->directory(2, [['id' => 'two', 'name' => 'Вторая', 'verified' => true]]))
                ->push($this->directory(1, [['id' => 'one', 'name' => 'Первая', 'verified' => true]]))
                ->push($this->directory(2, [['id' => 'two', 'name' => 'Вторая', 'verified' => true]])),
        ]);

        (new PriceListTjClient)->connect();
        $partners = (new PriceListTjClient)->partners();
        (new PriceListTjClient)->partners();

        $this->assertSame(['one', 'two'], array_column($partners, 'external_id'));
        $this->assertSame(['+992927430570'], $partners[0]['phones']);
        $encrypted = Storage::disk('local')->get(Storage::disk('local')->allFiles()[0]);
        $this->assertStringNotContainsString('private-token', $encrypted);
        $this->assertSame('private-token', Crypt::decryptString($encrypted));
        Http::assertSentCount(5);
    }

    public function test_expired_token_pauses_until_administrator_recovery(): void
    {
        $this->configure();
        Storage::disk('local')->put('external-prices/sessions/pricelist-tj-'.hash('sha256', '927430570').'.encrypted', Crypt::encryptString('expired'));
        Http::fake([
            'pricelist-tj.com/api/auth/login' => Http::response(['token' => 'renewed']),
            'pricelist-tj.com/api/companies?*' => Http::sequence()->push([], 401)->push($this->directory(1, [], total: 0, pages: 0)),
        ]);

        try {
            (new PriceListTjClient)->partners();
            $this->fail('Fetching must not renew an expired session.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 401', $exception->getMessage());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/auth/login'));
        $this->assertDatabaseHas('external_service_states', ['source' => 'pricelist_tj', 'status' => 'paused']);
    }

    public function test_preserves_price_discount_and_quantity_fields_across_all_pages(): void
    {
        $this->configure();
        Http::fake([
            'pricelist-tj.com/api/auth/login' => Http::response(['token' => 'token']),
            'pricelist-tj.com/api/companies/supplier?*' => Http::sequence()->push($this->prices(1, 'one'))->push($this->prices(2, 'two')),
        ]);

        $prices = (new PriceListTjClient)->prices('supplier');

        $this->assertSame(['one', 'two'], array_column($prices['items'], 'external_id'));
        $this->assertSame(['external_id' => 'one', 'name' => 'Лекарство', 'manufacturer' => 'Производитель', 'price' => 9,
            'original_price' => 10, 'discount_percent' => 10, 'quantity' => 0, 'expiry' => null], $prices['items'][0]);
        $this->assertSame(5, $prices['account_discount']);
    }

    public function test_rejects_restricted_prices(): void
    {
        $this->configure();
        Http::fake([
            'pricelist-tj.com/api/auth/login' => Http::response(['token' => 'token']),
            'pricelist-tj.com/api/companies/supplier?*' => Http::response([...$this->prices(1, 'one'), 'restricted' => true]),
        ]);

        $this->expectExceptionMessage('does not permit access');
        (new PriceListTjClient)->prices('supplier');
    }

    public function test_rejects_repeated_rows_instead_of_saving_an_incomplete_list(): void
    {
        $this->configure();
        Http::fake([
            'pricelist-tj.com/api/auth/login' => Http::response(['token' => 'token']),
            'pricelist-tj.com/api/companies/supplier?*' => Http::sequence()->push($this->prices(1, 'one'))->push($this->prices(2, 'one')),
        ]);

        $this->expectExceptionMessage('repeated rows');
        (new PriceListTjClient)->prices('supplier');
    }

    public function test_rejects_a_truncated_last_page(): void
    {
        $this->configure();
        Http::fake([
            'pricelist-tj.com/api/auth/login' => Http::response(['token' => 'token']),
            'pricelist-tj.com/api/companies/supplier?*' => Http::response([...$this->prices(1, 'one'), 'pagination' => ['page' => 1, 'total' => 2, 'totalPages' => 1]]),
        ]);

        $this->expectExceptionMessage('row count does not match');
        (new PriceListTjClient)->prices('supplier');
    }

    public function test_rejects_a_price_list_update_during_pagination(): void
    {
        $this->configure();
        Http::fake([
            'pricelist-tj.com/api/auth/login' => Http::response(['token' => 'token']),
            'pricelist-tj.com/api/companies/supplier?*' => Http::sequence()->push($this->prices(1, 'one'))
                ->push([...$this->prices(2, 'two'), 'company' => ['id' => 'supplier', 'name' => 'Поставщик', 'lastPriceUpdate' => 'changed']]),
        ]);

        $this->expectExceptionMessage('changed during extraction');
        (new PriceListTjClient)->prices('supplier');
    }

    public function test_login_failures_do_not_expose_remote_response_content(): void
    {
        $this->configure();
        Http::fake(['pricelist-tj.com/api/auth/login' => Http::response(['error' => 'secret-password'], 403)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PriceList login failed (HTTP 403).');
        (new PriceListTjClient)->connect();
    }

    private function configure(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['external-prices.pricelist_tj.phone' => '927430570', 'external-prices.pricelist_tj.password' => 'test-password']);
        Storage::disk('local')->put('external-prices/sessions/pricelist-tj-'.hash('sha256', '927430570').'.encrypted', Crypt::encryptString('private-token'));
    }

    /** @param list<array<string, mixed>> $companies
     * @return array<string, mixed>
     */
    private function directory(int $page, array $companies, int $total = 2, int $pages = 2): array
    {
        return ['companies' => $companies, 'pagination' => ['page' => $page, 'total' => $total, 'totalPages' => $pages]];
    }

    /** @return array<string, mixed> */
    private function prices(int $page, string $id): array
    {
        return ['company' => ['id' => 'supplier', 'name' => 'Поставщик', 'lastPriceUpdate' => '2026-11-11'],
            'restricted' => false, 'myDiscount' => 5,
            'priceItems' => [['id' => $id, 'companyId' => 'supplier', 'medicine' => 'Лекарство', 'manufacturer' => 'Производитель', 'price' => 9,
                'originalPrice' => 10, 'discountPercent' => 10, 'qty' => 0, 'expiry' => null]],
            'pagination' => ['page' => $page, 'total' => 2, 'totalPages' => 2]];
    }
}
