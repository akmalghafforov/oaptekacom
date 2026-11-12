<?php

namespace Tests\Feature;

use App\Services\IcorpClient;
use App\Services\IcorpCredentials;
use App\Services\IcorpFailure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class IcorpClientTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_discovers_partner_identities_and_available_catalogs_from_saved_cookies(): void
    {
        $this->configure();
        Http::fake(['icorp.tech/listpartners' => Http::response($this->directory())]);

        $partners = (new IcorpClient)->partners();

        $this->assertSame([
            ['source' => 'icorp', 'external_id' => '156', 'name' => 'Осон-Фарм', 'phones' => ['+992927430570'], 'catalog_id' => 'NDczODY=', 'published_at' => null],
            ['source' => 'icorp', 'external_id' => '157', 'name' => 'Без прайса', 'phones' => [], 'catalog_id' => null, 'published_at' => null],
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
        Http::fake([
            'icorp.tech/listpartners' => Http::response('<form action="/authorize">Login</form>'),
            'icorp.tech/login' => Http::response('Expired', 419),
        ]);

        $this->expectException(IcorpFailure::class);
        $this->expectExceptionMessage('session_expired');
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

        (new IcorpClient)->connect();
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

    public function test_extracts_partner_publication_date_separately_from_fetch_time(): void
    {
        $this->configure();
        Http::fake([
            'icorp.tech/listpartners' => Http::response(str_replace('data-partner-id="156"', 'data-partner-id="156" data-price-date="11.11.2026 09:30"', $this->directory())),
            'icorp.tech/productsFirmByOverhead?*' => Http::sequence()->push($this->catalog(1, 'one'))->push($this->catalog(2, 'two')),
        ]);
        $client = new IcorpClient;

        $partners = $client->partners();
        $prices = $client->prices('156');

        $this->assertSame('11.11.2026 09:30', $partners[0]['published_at']);
        $this->assertSame('11.11.2026 09:30', $prices['company']['published_at']);
        Http::assertSentCount(3);
    }

    public function test_workspace_csrf_failure_restarts_with_fresh_cookies_and_current_tokens_once(): void
    {
        $this->configure();
        Http::fake([
            'icorp.tech/login' => Http::sequence()
                ->push('', 302, ['Location' => '/choose'])
                ->push('<meta name="csrf-token" content="fresh-login">', 200, ['Set-Cookie' => 'session=fresh-cookie; Path=/; Secure']),
            'icorp.tech/authorize' => Http::sequence()
                ->push(['result' => 5, 'input' => '<input value="password">', 'csrf_token' => 'rotated-login'])
                ->push(['result' => 2]),
            'icorp.tech/choose' => Http::sequence()
                ->push('<meta name="csrf-token" content="stale-workspace"><button data-firm-id="303"></button>')
                ->push(file_get_contents(__DIR__.'/Fixtures/icorp-choose.html')),
            'icorp.tech/firm-entry' => Http::sequence()->push('Expired', 419)->push(['status' => true, 'redirect_url' => '/listpartners']),
            'icorp.tech/listpartners' => Http::response($this->directory()),
        ]);

        (new IcorpClient)->connect();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://icorp.tech/firm-entry'
            && $request->hasHeader('X-CSRF-TOKEN', 'fixture-workspace-token')
            && $request->hasHeader('Origin', 'https://icorp.tech')
            && $request->hasHeader('Referer', 'https://icorp.tech/choose')
            && $request['force_firm_session'] === 0);
        Http::assertSent(fn (Request $request): bool => ($request['auth_mode'] ?? null) === 'password' && $request->hasHeader('X-CSRF-TOKEN', 'rotated-login'));
        $session = json_decode(Crypt::decryptString(Storage::disk('local')->get($this->sessionPath())), true);
        $this->assertNotContains('private-cookie', array_column($session['cookies'], 'Value'));
        $this->assertContains('fresh-cookie', array_column($session['cookies'], 'Value'));
        Http::assertSentCount(9);
    }

    public function test_repeated_csrf_failure_is_authentication_and_does_not_save_a_session(): void
    {
        $this->configure();
        Storage::disk('local')->delete($this->sessionPath());
        Http::fake([
            'icorp.tech/login' => Http::response('', 302, ['Location' => '/choose']),
            'icorp.tech/choose' => Http::response('<meta name="csrf-token" content="token"><button data-firm-id="303"></button>'),
            'icorp.tech/firm-entry' => Http::response('Expired', 419),
        ]);

        try {
            (new IcorpClient)->connect();
            $this->fail('Repeated CSRF failure must stop authentication.');
        } catch (IcorpFailure $exception) {
            $this->assertSame('authentication', $exception->reason);
            $this->assertSame(['stage' => 'workspace', 'code' => 'http_error', 'http_status' => 419], $exception->diagnostic());
        }
        Storage::disk('local')->assertMissing($this->sessionPath());
        Http::assertSentCount(6);
    }

    public function test_successful_workspace_http_response_must_confirm_entry(): void
    {
        $this->configure();
        Http::fake([
            'icorp.tech/login' => Http::response('', 302, ['Location' => '/choose']),
            'icorp.tech/choose' => Http::response('<meta name="csrf-token" content="token"><button data-firm-id="303"></button>'),
            'icorp.tech/firm-entry' => Http::response(['status' => false]),
        ]);

        $this->expectExceptionMessage('entry_rejected');
        (new IcorpClient)->connect();
    }

    public function test_expired_catalog_pauses_without_attempting_automatic_login(): void
    {
        $this->configure();
        Http::fake([
            'icorp.tech/login' => Http::response('', 302, ['Location' => '/choose']),
            'icorp.tech/choose' => Http::response('<meta name="csrf-token" content="token"><button data-firm-id="303"></button>'),
            'icorp.tech/firm-entry' => Http::response(['status' => true]),
            'icorp.tech/listpartners' => Http::response($this->directory()),
            'icorp.tech/productsFirmByOverhead?*' => Http::sequence()->push('', 302, ['Location' => '/login'])->push($this->catalog(1, 'one'))->push($this->catalog(2, 'two')),
        ]);

        try {
            (new IcorpClient)->prices('156');
            $this->fail('Fetching must not renew expired sessions.');
        } catch (IcorpFailure $exception) {
            $this->assertSame('authentication', $exception->reason);
        }
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/login'));
        $this->assertDatabaseHas('external_service_states', ['source' => 'icorp', 'status' => 'paused']);
    }

    public function test_extracts_the_current_catalog_markup_from_a_sanitized_live_row(): void
    {
        $this->configure();
        Http::fake([
            'icorp.tech/listpartners' => Http::response('<article data-partner-id="156" data-price-date="12.11.2026 09:00"><h3>Поставщик</h3><a href="/priceview/sample-catalog">Прайс</a></article>'),
            'icorp.tech/productsFirmByOverhead?*' => Http::response(file_get_contents(__DIR__.'/Fixtures/icorp-catalog.html')),
        ]);

        $prices = (new IcorpClient)->prices('156');

        $this->assertCount(1, $prices['items']);
        $this->assertSame('sample-row', $prices['items'][0]['external_id']);
        $this->assertSame('Лекарство', $prices['items'][0]['name']);
        $this->assertSame('12.50', $prices['items'][0]['price']);
        $this->assertSame('0', $prices['items'][0]['discount_percent']);
        $this->assertNull($prices['items'][0]['quantity']);
        $this->assertSame('12.11.2026 09:00', $prices['company']['published_at']);
        Http::assertSentCount(2);
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
        return app(IcorpCredentials::class)->sessionPath();
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
