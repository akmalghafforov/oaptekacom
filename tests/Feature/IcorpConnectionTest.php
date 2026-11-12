<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\IcorpCredentials;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class IcorpConnectionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_admin_can_save_encrypted_credentials_and_connect_without_exposing_secrets(): void
    {
        Storage::fake('local');
        config(['external-prices.icorp.phone' => null, 'external-prices.icorp.password' => null, 'external-prices.icorp.firm_id' => '303']);
        $this->fakeConnection();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($admin)->from(route('admin.supplier-sync.index'))->post(route('admin.supplier-sync.connect', 'icorp'), ['phone' => '+992 927430570', 'password' => 'private-password'])
            ->assertRedirect(route('admin.supplier-sync.index'))->assertSessionHas('success', 'Подключение установлено.');

        $encrypted = Storage::disk('local')->get('external-prices/credentials/icorp.encrypted');
        $this->assertStringNotContainsString('private-password', $encrypted);
        $this->assertStringNotContainsString('927430570', $encrypted);
        $this->assertSame('private-password', json_decode(Crypt::decryptString($encrypted), true)['password']);
        $audit = AuditEvent::where('event', 'supplier_sync.credentials_updated')->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertStringNotContainsString('private-password', AuditEvent::all()->toJson());
        $this->assertStringNotContainsString('private-password', json_encode(session()->all()));
        $this->get(route('admin.supplier-sync.index'))->assertSee('927430570')->assertSee('Сохранить и подключить')->assertDontSee('private-password')
            ->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://icorp.tech/authorize' && ($request['password'] ?? null) === 'private-password');
    }

    public function test_blank_password_is_retained_only_for_the_same_normalized_login(): void
    {
        Storage::fake('local');
        config(['external-prices.icorp.firm_id' => '303']);
        app(IcorpCredentials::class)->save('927430570', 'private-password');
        $oldSessionPath = app(IcorpCredentials::class)->sessionPath();
        $this->fakeConnection();
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));

        $this->post(route('admin.supplier-sync.connect', 'icorp'), ['phone' => '+992927430570', 'password' => ''])->assertSessionHas('success');
        $this->assertSame('private-password', app(IcorpCredentials::class)->current()['password']);
        $this->assertNotSame($oldSessionPath, app(IcorpCredentials::class)->sessionPath());
        $this->postJson(route('admin.supplier-sync.connect', 'icorp'), ['phone' => '987654321', 'password' => ''])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame('927430570', app(IcorpCredentials::class)->current()['phone']);
    }

    public function test_first_credentials_require_a_password_and_password_is_never_flashed_on_validation_failure(): void
    {
        Storage::fake('local');
        config(['external-prices.icorp.phone' => '927430570', 'external-prices.icorp.password' => 'environment-password']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));

        $this->postJson(route('admin.supplier-sync.connect', 'icorp'), ['phone' => '927430570'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->from(route('admin.supplier-sync.index'))->post(route('admin.supplier-sync.connect', 'icorp'), ['phone' => 'bad', 'password' => 'private-password'])->assertRedirect();
        $this->get(route('admin.supplier-sync.index'))->assertDontSee('private-password')->assertSee('Введите телефон iCorp');
        $this->assertStringNotContainsString('private-password', json_encode(session()->all()));
        Storage::disk('local')->assertMissing('external-prices/credentials/icorp.encrypted');
        Http::assertNothingSent();
    }

    public function test_saved_credentials_remain_available_when_connection_fails(): void
    {
        Storage::fake('local');
        config(['external-prices.icorp.firm_id' => '303']);
        Http::preventStrayRequests();
        Http::fake(['icorp.tech/login' => Http::response('Unavailable', 503)]);
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));

        $this->post(route('admin.supplier-sync.connect', 'icorp'), ['phone' => '927430570', 'password' => 'private-password'])
            ->assertSessionHas('warning', 'Данные сохранены, подключение не выполнено: ошибка соединения.');
        $this->assertSame('private-password', app(IcorpCredentials::class)->current()['password']);
        Storage::disk('local')->assertMissing(app(IcorpCredentials::class)->sessionPath());
    }

    public function test_suppliers_cannot_read_or_change_the_shared_connection(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->wholesaler()->create());

        $this->get(route('admin.supplier-sync.index'))->assertForbidden();
        $this->post(route('admin.supplier-sync.connect', 'icorp'), ['phone' => '927430570', 'password' => 'private-password'])->assertForbidden();
        Storage::disk('local')->assertMissing('external-prices/credentials/icorp.encrypted');
        Http::assertNothingSent();
    }

    public function test_saved_credentials_override_environment_and_can_be_replaced_without_restarting_the_client(): void
    {
        Storage::fake('local');
        config(['external-prices.icorp.phone' => '927430570', 'external-prices.icorp.password' => 'environment-password', 'external-prices.icorp.firm_id' => '303']);
        $credentials = app(IcorpCredentials::class);
        $this->assertSame('environment-password', $credentials->current()['password']);
        $environmentSession = $credentials->sessionPath();
        $credentials->save('987654321', 'replacement-password');
        $this->assertSame('replacement-password', $credentials->current()['password']);
        $this->assertNotSame($environmentSession, $credentials->sessionPath());
        $savedSession = $credentials->sessionPath();
        config(['external-prices.icorp.firm_id' => '404']);
        $this->assertNotSame($savedSession, $credentials->sessionPath());
    }

    public function test_corrupted_saved_credentials_do_not_silently_fall_back_and_can_be_replaced(): void
    {
        Storage::fake('local');
        config(['external-prices.icorp.phone' => '927430570', 'external-prices.icorp.password' => 'environment-password', 'external-prices.icorp.firm_id' => '303']);
        Storage::disk('local')->put('external-prices/credentials/icorp.encrypted', 'corrupted');
        $this->fakeConnection();
        $this->actingAs(User::factory()->admin()->create(['two_factor_confirmed_at' => now()]));

        $this->post(route('admin.supplier-sync.connect', 'icorp'))->assertSessionHas('warning', 'Подключение не выполнено: требуется авторизация.');
        Http::assertNothingSent();
        $this->get(route('admin.supplier-sync.index'))->assertSee('Сохранить и подключить')->assertDontSee('environment-password');
        $this->post(route('admin.supplier-sync.connect', 'icorp'), ['phone' => '927430570', 'password' => 'replacement-password'])->assertSessionHas('success');
        $this->assertSame('replacement-password', app(IcorpCredentials::class)->current()['password']);
    }

    private function fakeConnection(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'icorp.tech/login' => Http::response('<meta name="csrf-token" content="login-token">'),
            'icorp.tech/authorize' => Http::sequence()->push(['result' => 5, 'input' => '<input value="password">'])->push(['result' => 2]),
            'icorp.tech/choose' => Http::response('<meta name="csrf-token" content="workspace-token"><button data-firm-id="303">Вход</button>'),
            'icorp.tech/firm-entry' => Http::response(['status' => true, 'redirect_url' => '/listpartners']),
            'icorp.tech/listpartners' => Http::response('<article data-partner-id="171"><h3>Поставщик</h3></article>'),
        ]);
    }
}
