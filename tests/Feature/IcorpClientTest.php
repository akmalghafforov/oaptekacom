<?php

namespace Tests\Feature;

use App\Services\IcorpClient;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IcorpClientTest extends TestCase
{
    public function test_discovers_partner_identities_and_available_catalogs_from_saved_cookies(): void
    {
        $this->configure();
        Http::fake(['icorp.tech/listpartners' => Http::response($this->directory())]);

        $partners = (new IcorpClient)->partners();

        $this->assertSame([
            ['source' => 'icorp', 'external_id' => '156', 'name' => 'Осон-Фарм', 'phones' => ['+992927430570']],
            ['source' => 'icorp', 'external_id' => '157', 'name' => 'Без прайса', 'phones' => []],
        ], $partners);
        $encrypted = Storage::disk('local')->get($this->sessionPath());
        $this->assertStringNotContainsString('private-cookie', $encrypted);
        $this->assertStringContainsString('private-cookie', Crypt::decryptString($encrypted));
    }

    public function test_extracts_all_rows_without_using_cart_quantity_as_stock_quantity(): void
    {
        $this->configure();
        Http::fake([
            'icorp.tech/listpartners' => Http::response($this->directory()),
            'icorp.tech/productsFirmByOverhead?*' => Http::sequence()->push($this->catalog(1, 'one'))->push($this->catalog(2, 'two')),
        ]);

        $prices = (new IcorpClient)->prices('156');

        $this->assertSame(['one', 'two'], array_column($prices['items'], 'external_id'));
        $this->assertSame('Лекарство', $prices['items'][0]['name']);
        $this->assertSame('12.50', $prices['items'][0]['price']);
        $this->assertNull($prices['items'][0]['quantity']);
        $this->assertNull($prices['items'][0]['expiry']);
    }

    public function test_reports_unavailable_catalogs_without_requesting_prices(): void
    {
        $this->configure();
        Http::fake(['icorp.tech/listpartners' => Http::response($this->directory())]);

        $prices = (new IcorpClient)->prices('157');

        $this->assertTrue($prices['unavailable']);
        $this->assertSame([], $prices['items']);
        Http::assertSentCount(1);
    }

    public function test_rejects_rows_for_a_different_supplier(): void
    {
        $this->configure();
        Http::fake([
            'icorp.tech/listpartners' => Http::response($this->directory()),
            'icorp.tech/productsFirmByOverhead?*' => Http::response(str_replace('MTU2', 'MTU3', $this->catalog(1, 'one'))),
        ]);

        $this->expectExceptionMessage('invalid or repeated price row');
        (new IcorpClient)->prices('156');
    }

    public function test_rejects_duplicate_rows_across_pages(): void
    {
        $this->configure();
        Http::fake([
            'icorp.tech/listpartners' => Http::response($this->directory()),
            'icorp.tech/productsFirmByOverhead?*' => Http::sequence()->push($this->catalog(1, 'one'))->push($this->catalog(2, 'one')),
        ]);

        $this->expectExceptionMessage('invalid or repeated price row');
        (new IcorpClient)->prices('156');
    }

    public function test_does_not_treat_an_expired_login_page_as_an_empty_directory(): void
    {
        $this->configure();
        Http::fake(['icorp.tech/listpartners' => Http::response('<form action="/authorize">Login</form>')]);

        $this->expectExceptionMessage('No iCorp partner cards found');
        (new IcorpClient)->partners();
    }

    public function test_reauthenticates_with_password_and_selects_the_configured_workspace(): void
    {
        $this->configure();
        Storage::disk('local')->delete($this->sessionPath());
        Http::fake([
            'icorp.tech/login' => Http::response('<meta name="csrf-token" content="login-csrf">'),
            'icorp.tech/authorize' => Http::sequence()->push(json_encode(['result' => '5', 'input' => '<input value="password">']))->push(json_encode(['result' => '2'])),
            'icorp.tech/choose' => Http::response('<meta name="csrf-token" content="entry-csrf"><button data-firm-id="303">Вход</button>'),
            'icorp.tech/firm-entry' => Http::response(['success' => true]),
            'icorp.tech/listpartners' => Http::response($this->directory()),
        ]);

        $this->assertCount(2, (new IcorpClient)->partners());
        Storage::disk('local')->assertExists($this->sessionPath());
        Http::assertSent(fn ($request): bool => $request->url() === 'https://icorp.tech/authorize' && ($request['password'] ?? null) === 'test-password' && $request['auth_mode'] === 'password');
    }

    public function test_session_renewal_never_forces_another_device_out_without_explicit_takeover(): void
    {
        $this->configure();
        Http::fake([
            'icorp.tech/login' => Http::response('', 302, ['Location' => '/choose']),
            'icorp.tech/choose' => Http::response('<meta name="csrf-token" content="entry-csrf"><button data-firm-id="303">Вход</button>'),
            'icorp.tech/firm-entry' => Http::response([], 409),
        ]);

        try {
            (new IcorpClient)->connect();
            $this->fail('Workspace conflict must stop renewal.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('another device', $exception->getMessage());
        }

        Http::assertSent(fn ($request): bool => $request->url() === 'https://icorp.tech/firm-entry' && $request['force_firm_session'] === 0);
    }

    private function configure(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['external-prices.icorp.phone' => '927430570', 'external-prices.icorp.password' => 'test-password', 'external-prices.icorp.firm_id' => '303']);
        Storage::disk('local')->put($this->sessionPath(), Crypt::encryptString(json_encode([
            'cookies' => [['Name' => 'session', 'Value' => 'private-cookie', 'Domain' => 'icorp.tech', 'Path' => '/']], 'user_agent' => 'Test browser',
        ], JSON_THROW_ON_ERROR)));
    }

    private function sessionPath(): string
    {
        return 'external-prices/sessions/icorp-'.hash('sha256', '927430570').'.encrypted';
    }

    private function directory(): string
    {
        return '<article data-partner-id="156"><h3>Осон-Фарм</h3><a href="tel:+992927430570">Телефон</a><a href="https://icorp.tech/priceview/NDczODY=">Прайс</a></article>'
            .'<article data-partner-id="157"><h3>Без прайса</h3></article>';
    }

    private function catalog(int $position, string $id): string
    {
        return '<div class="catalog-summary">Показано '.$position.'-'.$position.' из 2</div><table><tr class="catalog-cart-source">'
            .'<td class="catalog-title">Лекарство</td><td class="catalog-price-main">12.50</td><td class="catalog-discount-badge">0%</td><td class="catalog-col-expire">-</td>'
            .'<td><input class="catalog-prid" value="'.$id.'"><input class="catalog-over" value="NDczODY="><input class="catalog-firm" value="MTU2"><input class="catalog-crt" value="1"></td></tr></table>';
    }
}
