<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OneTimePassword;
use App\Models\User;
use App\Services\OsonSmsGateway;
use App\Services\PhoneOtpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PharmacyPasswordTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.development_otp_code' => null, 'cache.default' => 'array', 'cache.limiter' => 'array', 'services.oson_sms.driver' => 'http']);
        $this->freezeTime();
    }

    public function test_password_survives_logout_and_time_without_sending_sms(): void
    {
        $this->mock(OsonSmsGateway::class)->shouldNotReceive('send');
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => '123456']);
        foreach ([0, 400] as $days) {
            $this->travel($days)->days();
            $this->post(route('login.authenticate'), ['phone' => '90 123-45-67', 'password' => '123456'])->assertRedirectToRoute('catalog');
            $this->assertAuthenticatedAs($user);
            $this->post(route('logout'))->assertRedirectToRoute('login');
            $this->assertGuest();
        }
        $this->assertDatabaseCount('one_time_passwords', 0);
    }

    public function test_replacement_commits_only_after_delivery_and_does_not_authenticate(): void
    {
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => '123456']);
        $supplier = User::factory()->wholesaler()->create(['phone' => $user->phone, 'password' => '654321']);
        $supplierHash = $supplier->password;
        $gateway = $this->mock(OsonSmsGateway::class);
        $gateway->shouldReceive('send')->once()->ordered()->andThrow(new \RuntimeException('Unavailable'));
        $gateway->shouldReceive('send')->once()->ordered()->andReturnUsing(function (string $phone, string $message) use ($user): array {
            $this->assertSame($user->phone, $phone);
            $this->assertTrue(Hash::check('123456', $user->fresh()->password));
            $this->assertMatchesRegularExpression('/^Ваш пароль для входа в OAPTEKA: [0-9]{6}\. Сохраните его для будущих входов\. Не сообщайте его никому\.$/u', $message);

            return ['transaction_id' => 'test', 'message_id' => '1'];
        });
        $this->post(route('login.password.send'), ['phone' => $user->phone])->assertSessionHasErrors('phone');
        $this->assertTrue(Hash::check('123456', $user->fresh()->password));
        $this->post(route('login.password.send'), ['phone' => $user->phone])->assertRedirectToRoute('login');
        $this->assertGuest();
        $this->assertSame(OneTimePassword::where('status', 'sent')->firstOrFail()->code_hash, $user->fresh()->password);
        $this->assertSame($supplierHash, $supplier->fresh()->password);
        $this->post(route('login.authenticate'), ['phone' => $user->phone, 'password' => '123456'])->assertSessionHasErrors('password');
    }

    public function test_registration_revisits_do_not_send_or_replace_password_and_password_is_reusable(): void
    {
        config(['services.oson_sms.driver' => 'dummy', 'services.oson_sms.dummy_code' => '123456']);
        $this->mock(OsonSmsGateway::class)->shouldReceive('send')->once()->andReturn(['transaction_id' => 'test', 'message_id' => null]);
        $this->post(route('register.phone'), ['phone' => '901234567'])->assertRedirectToRoute('register.details.form');
        $this->post(route('register.details.store'), ['pharmacy_name' => 'Аптека Новая', 'city' => 'Душанбе'])->assertRedirectToRoute('register.otp.form');
        $user = User::where('phone', '+992901234567')->firstOrFail();
        $hash = $user->password;
        $this->assertTrue(Hash::check('123456', $hash));
        $this->travel(1)->days();
        $this->post(route('register.phone'), ['phone' => $user->phone])->assertRedirectToRoute('register.otp.form');
        $this->post(route('register.details.store'), ['pharmacy_name' => 'Аптека Новая', 'city' => 'Душанбе'])->assertRedirectToRoute('register.otp.form');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseCount('one_time_passwords', 1);
        $this->post(route('register.otp.verify'), ['code' => '123456'])->assertRedirectToRoute('subscription.create');
        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->get(route('catalog'))->assertRedirectToRoute('subscription.create');
        $this->post(route('logout'));
        $this->post(route('login.authenticate'), ['phone' => $user->phone, 'password' => '123456'])->assertRedirectToRoute('catalog');
        $this->assertAuthenticatedAs($user);
        $this->assertNull(OneTimePassword::firstOrFail()->consumed_at);
    }

    public function test_failed_attempt_limit_expires_and_normalizes_phone_and_isolates_ip(): void
    {
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => '123456']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.authenticate'), ['phone' => '901234567', 'password' => '111111'])->assertSessionHasErrors('password');
        }
        $this->post(route('login.authenticate'), ['phone' => '+992 (90) 123-45-67', 'password' => '123456'])
            ->assertSessionHasErrors(['password' => 'Слишком много попыток. Попробуйте через минуту.']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post(route('login.authenticate'), ['phone' => $user->phone, 'password' => '123456'])->assertRedirectToRoute('catalog');
        $this->post(route('logout'));
        $this->travel(61)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->post(route('login.authenticate'), ['phone' => $user->phone, 'password' => '123456'])->assertRedirectToRoute('catalog');
    }

    public function test_blocked_and_missing_password_accounts_cannot_login_with_real_sms_driver(): void
    {
        $blocked = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => '000000', 'is_blocked' => true]);
        $missing = User::factory()->pharmacy()->create(['phone' => '+992901234568', 'password' => null]);
        foreach ([$blocked, $missing] as $user) {
            $this->post(route('login.authenticate'), ['phone' => $user->phone, 'password' => '000000'])->assertSessionHasErrors('password');
            $this->assertGuest();
        }
        $this->post(route('login.password.send'), ['phone' => $blocked->phone])->assertRedirectToRoute('login');
        $this->assertDatabaseCount('one_time_passwords', 0);
    }

    public function test_replacement_invalidates_pending_session_confirmation_and_preserves_active_sessions(): void
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => '123456']);
        DB::table('sessions')->insert(['id' => 'original-session', 'user_id' => $user->id, 'payload' => base64_encode(json_encode([auth()->guard()->getName() => $user->id])), 'last_activity' => now()->timestamp]);
        $this->post(route('login.authenticate'), ['phone' => $user->phone, 'password' => '123456'])->assertRedirectToRoute('login.session.confirmation');
        $this->assertGuest();
        config(['services.oson_sms.driver' => 'dummy', 'services.oson_sms.dummy_code' => '654321']);
        $this->mock(OsonSmsGateway::class)->shouldReceive('send')->once()->andReturn(['transaction_id' => 'test', 'message_id' => null]);
        $this->post(route('login.password.send'), ['phone' => $user->phone])->assertRedirectToRoute('login');
        $this->get(route('login.session.confirmation'))->assertNotFound();
        $this->post(route('login.session.confirm'))->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => 'original-session']);
        $this->assertGuest();
    }

    public function test_password_issuance_uses_delivery_lock_and_initial_issuance_is_idempotent(): void
    {
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => null]);
        $this->mock(OsonSmsGateway::class)->shouldReceive('send')->once()->andReturn(['transaction_id' => 'test', 'message_id' => null]);
        $service = app(PhoneOtpService::class);
        $service->send('registration', $user->phone, UserRole::Pharmacy, true);
        $hash = $user->fresh()->password;
        $service->send('registration', $user->phone, UserRole::Pharmacy, true);
        $this->assertSame($hash, $user->fresh()->password);
        $lock = Cache::lock('otp:lock:'.hash('sha256', $user->phone), 60);
        $lock->get();
        try {
            $this->post(route('login.password.send'), ['phone' => $user->phone])->assertSessionHasErrors('phone');
            $this->assertSame($hash, $user->fresh()->password);
        } finally {
            $lock->release();
        }
    }

    #[TestWith(['sent'])]
    #[TestWith(['consumed'])]
    #[TestWith(['invalidated'])]
    #[TestWith(['expired'])]
    public function test_backfill_selects_latest_delivered_current_phone_pharmacy_password_only(string $status): void
    {
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567']);
        $missing = User::factory()->pharmacy()->create(['phone' => '+992901234568']);
        $supplier = User::factory()->wholesaler()->create(['phone' => $user->phone]);
        $supplierHash = $supplier->password;
        OneTimePassword::factory()->create(['phone' => $user->phone, 'status' => 'consumed', 'consumed_at' => now()->subDays(2), 'sent_at' => now()->subDays(3), 'expires_at' => now()->subDays(3)]);
        $chosen = OneTimePassword::factory()->create(['phone' => $user->phone, 'purpose' => 'registration', 'status' => $status, 'consumed_at' => now()->subDay(), 'sent_at' => now()->subDays(2), 'expires_at' => now()->subDays(2)]);
        foreach ([['status' => 'failed'], ['purpose' => 'phone_change'], ['account_type' => UserRole::Wholesaler], ['sent_at' => null], ['phone' => '+992909999999']] as $attributes) {
            OneTimePassword::factory()->create([...['phone' => $user->phone], ...$attributes]);
        }
        $migration = require database_path('migrations/2026_10_28_113258_backfill_pharmacy_passwords.php');
        $this->mock(OsonSmsGateway::class)->shouldNotReceive('send');
        $migration->up();
        $this->assertSame($chosen->code_hash, $user->fresh()->password);
        $this->assertNull($missing->fresh()->password);
        $this->assertSame($supplierHash, $supplier->fresh()->password);
        $migration->down();
        $this->assertSame($chosen->code_hash, $user->fresh()->password);
    }

    public function test_legacy_phone_login_never_sends_sms_and_rejects_same_phone_supplier_password(): void
    {
        $this->mock(OsonSmsGateway::class)->shouldNotReceive('send');
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => '123456']);
        User::factory()->wholesaler()->create(['phone' => $user->phone, 'password' => '654321']);
        $this->post(route('login.otp.send'), ['phone' => $user->phone])->assertRedirectToRoute('login.otp.form');
        $this->post(route('login.otp.verify'), ['code' => '654321'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post(route('login.otp.verify'), ['code' => '123456'])->assertRedirectToRoute('catalog');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('one_time_passwords', 0);
    }

    public function test_phone_change_verification_preserves_saved_password(): void
    {
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => '123456']);
        $hash = $user->password;
        $this->mock(OsonSmsGateway::class)->shouldReceive('send')->once()->withArgs(function (string $phone, string $message): bool {
            return $phone === '+992909999999' && str_contains($message, 'Код подтверждения') && ! str_contains($message, 'Ваш пароль');
        })->andReturn(['transaction_id' => 'test', 'message_id' => null]);
        config(['services.oson_sms.driver' => 'dummy', 'services.oson_sms.dummy_code' => '654321']);
        $this->actingAs($user)->post(route('profile.phone.send'), ['phone' => '+992909999999'])->assertRedirectToRoute('profile.phone.verify');
        $this->get(route('profile.phone.verify'))->assertSee('Код из SMS')->assertDontSee('Пароль из SMS');
        $this->post(route('profile.phone.confirm'), ['code' => '654321'])->assertRedirectToRoute('profile.edit');
        $this->assertSame('+992909999999', $user->fresh()->phone);
        $this->assertSame($hash, $user->fresh()->password);
        $this->post(route('logout'));
        $this->post(route('login.authenticate'), ['phone' => '+992909999999', 'password' => '123456'])->assertRedirectToRoute('catalog');
        $this->assertAuthenticatedAs($user);
    }

    #[TestWith(['sent', true])]
    #[TestWith(['consumed', true])]
    #[TestWith(['expired', true])]
    #[TestWith(['failed', true])]
    #[TestWith(['pending', true])]
    public function test_login_mentions_an_already_delivered_saved_code(string $status, bool $showsNotice): void
    {
        $this->mock(OsonSmsGateway::class)->shouldNotReceive('send');
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => '123456']);
        OneTimePassword::factory()->create([
            'phone' => $user->phone, 'code_hash' => $user->password,
            'status' => $status, 'expires_at' => now()->subDay(),
        ]);
        $response = $this->withSession(['phone_otp.login' => $user->phone])->get(route('login.otp.form'))->assertOk();
        $notice = 'Используйте код из предыдущего SMS.';
        if ($showsNotice) {
            $response->assertSee($notice);
        } else {
            $response->assertDontSee($notice);
        }
    }

    public function test_login_shows_reuse_instruction_even_without_matching_delivery_history(): void
    {
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => '123456']);
        OneTimePassword::factory()->create(['phone' => $user->phone, 'code_hash' => Hash::make('654321')]);
        OneTimePassword::factory()->create(['phone' => $user->phone, 'code_hash' => $user->password, 'purpose' => 'phone_change']);
        OneTimePassword::factory()->create(['phone' => $user->phone, 'code_hash' => $user->password, 'account_type' => UserRole::Wholesaler]);
        $this->withSession(['phone_otp.login' => $user->phone])->get(route('login.otp.form'))
            ->assertOk()->assertSee('Используйте код из предыдущего SMS.');
    }

    #[TestWith([null])]
    #[TestWith(['123456'])]
    public function test_configured_dummy_code_logs_in_without_issuing_or_replacing_password(?string $savedPassword): void
    {
        config(['services.oson_sms.driver' => 'dummy', 'services.oson_sms.dummy_code' => '654321']);
        $this->mock(OsonSmsGateway::class)->shouldNotReceive('send');
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => $savedPassword]);
        $hash = $user->password;
        $this->withSession(['phone_otp.login' => $user->phone])->post(route('login.otp.verify'), ['code' => '654321'])->assertRedirectToRoute('catalog');
        $this->assertAuthenticatedAs($user);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertDatabaseCount('one_time_passwords', 0);
    }

    public function test_dummy_code_cannot_login_blocked_or_supplier_accounts(): void
    {
        config(['services.oson_sms.driver' => 'dummy', 'services.oson_sms.dummy_code' => '654321']);
        $blocked = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'is_blocked' => true]);
        $supplier = User::factory()->wholesaler()->create(['phone' => '+992901234568']);
        foreach ([$blocked, $supplier] as $user) {
            $this->withSession(['phone_otp.login' => $user->phone])->post(route('login.otp.verify'), ['code' => '654321'])->assertSessionHasErrors('code');
            $this->assertGuest();
        }
    }

    #[TestWith(['local', true])]
    #[TestWith(['testing', true])]
    #[TestWith(['production', false])]
    public function test_development_code_is_accepted_only_in_development_environments(string $environment, bool $allowed): void
    {
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => null]);
        $this->app->instance('env', $environment);
        config(['auth.development_otp_code' => '000000', 'services.oson_sms.driver' => 'oson']);
        $this->mock(OsonSmsGateway::class)->shouldNotReceive('send');
        $result = app(PhoneOtpService::class)->verifyPassword($user->phone, '000000', '127.0.0.1');
        $this->assertSame($allowed ? $user->id : null, $result?->id);
        $this->assertNull($user->fresh()->password);
    }

    public function test_development_code_authenticates_from_the_pharmacy_code_entry_page(): void
    {
        config(['auth.development_otp_code' => '000000', 'services.oson_sms.driver' => 'oson']);
        $this->mock(OsonSmsGateway::class)->shouldNotReceive('send');
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => null]);
        $this->withSession(['phone_otp.login' => $user->phone])->post(route('login.otp.verify'), ['code' => '000000'])->assertRedirectToRoute('catalog');
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'));
        $user->forceFill(['is_blocked' => true])->save();
        $this->withSession(['phone_otp.login' => $user->phone])->post(route('login.otp.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_password_forms_and_validation_never_flash_secrets(): void
    {
        $this->get(route('login'))->assertSee('name="phone"', false)->assertDontSee('name="password"', false)->assertDontSee('name="code"', false)->assertSee('action="'.route('login.otp.send').'"', false)->assertSee('Продолжить');
        $this->withSession(['phone_otp.login' => '+992901234567'])->get(route('login.otp.form'))
            ->assertSee('Код из SMS')->assertSee('autocomplete="current-password"', false)
            ->assertSee('name="code" type="text"', false)->assertSee('data-sms-code-mask', false)
            ->assertDontSee('type="password"', false);
        $this->get(route('login.password.request'))->assertOk()->assertSee('Отправить новый пароль');
        $this->post(route('login.authenticate'), ['phone' => 'invalid', 'password' => '123456'])->assertSessionHasErrors('phone')->assertSessionMissing('_old_input.password');
        $this->post(route('login.otp.verify'), ['code' => '12345'])->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
    }
}
