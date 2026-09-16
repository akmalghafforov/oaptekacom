<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlan;
use App\Enums\TradeMode;
use App\Enums\UserRole;
use App\Models\OneTimePassword;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneOtpAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private mixed $developmentOtpCode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->developmentOtpCode = config('auth.development_otp_code');
        config()->set('auth.development_otp_code', null);
    }

    protected function tearDown(): void
    {
        config()->set('auth.development_otp_code', $this->developmentOtpCode);

        parent::tearDown();
    }

    public function test_each_role_has_its_own_login_page(): void
    {
        $this->get(route('login'))
            ->assertViewIs('auth.login')
            ->assertSee('data-phone-mask-default', false)
            ->assertSee('value="+992"', false)
            ->assertSee('+992 (00) 000-00-00', false);
        $this->get(route('admin.login'))->assertViewIs('auth.admin-login');
        $this->get(route('provider.login'))->assertViewIs('auth.provider-login');
    }

    public function test_phone_login_verification_uses_phone_and_sms_code_masks(): void
    {
        $this->withSession(['phone_otp.login' => '+992901234567'])
            ->get(route('login.otp.form'))
            ->assertSee('data-phone-mask', false)
            ->assertSee('data-sms-code-mask', false)
            ->assertSee('page-container max-w-lg', false);
    }

    public function test_unknown_phone_on_the_login_screen_is_routed_to_the_pharmacy_name_step(): void
    {
        Http::preventStrayRequests();

        $this->post(route('login.otp.send'), ['phone' => '901234567'])
            ->assertRedirect(route('register.details.form'));

        $this->get(route('register.details.form'))
            ->assertViewIs('auth.register-name')
            ->assertSee('Название аптеки')
            ->assertSee('page-container max-w-lg', false);
        $this->assertDatabaseCount('one_time_passwords', 0);
    }

    public function test_unknown_phone_collects_a_name_then_creates_a_pending_pharmacy_before_sending_sms(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.osonsms.com/sendsms_v1.php*' => Http::response(['status' => 'success', 'transaction_id' => 'provider-1'])]);

        $this->post(route('register'), ['phone' => '901234567'])->assertRedirect(route('register.details.form'));
        $this->assertDatabaseCount('users', 0);

        $this->post(route('register.details.store'), ['pharmacy_name' => 'Аптека Тест'])->assertRedirect(route('register.otp.form'));
        $this->assertDatabaseHas('organizations', ['name' => 'Аптека Тест', 'phone' => '+992901234567', 'status' => 'pending']);
        $this->assertDatabaseHas('users', ['phone' => '+992901234567', 'role' => 'pharmacy', 'password' => null, 'phone_verified_at' => null]);
        $otp = OneTimePassword::firstOrFail();
        $otp->update(['code_hash' => Hash::make('123456')]);

        $this->post(route('register.otp.verify'), ['phone' => '+992901234567', 'code' => '123456'])->assertRedirect(route('subscription.create'));

        $user = User::where('phone', '+992901234567')->firstOrFail();
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame('pending', $user->organization->status);
        $this->assertAuthenticatedAs($user);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.osonsms.com/sendsms_v1.php'));
    }

    public function test_failed_registration_sms_delivery_preserves_pending_application_and_retry_does_not_duplicate_it(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.osonsms.com/sendsms_v1.php*' => Http::sequence()
                ->pushStatus(500)
                ->push(['status' => 'success', 'transaction_id' => 'provider-2']),
        ]);

        $this->post(route('register'), ['phone' => '901234567'])->assertRedirect(route('register.details.form'));
        $this->post(route('register.details.store'), ['pharmacy_name' => 'Аптека Тест'])->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('one_time_passwords', ['purpose' => 'registration', 'status' => 'failed']);

        $this->post(route('register'), ['phone' => '901234567'])->assertRedirect(route('register.otp.form'));

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('one_time_passwords', ['purpose' => 'registration', 'status' => 'sent']);
    }

    public function test_invalid_registration_code_leaves_pending_application_unverified_and_signed_out(): void
    {
        $organization = Organization::factory()->pending()->create(['phone' => '+992901234567']);
        $user = User::factory()->pharmacy($organization)->create(['phone' => '+992901234567']);
        OneTimePassword::factory()->create(['purpose' => 'registration', 'phone' => $user->phone, 'code_hash' => Hash::make('123456')]);

        $this->withSession(['phone_otp.registration' => ['phone' => $user->phone, 'user_id' => $user->id]])
            ->post(route('register.otp.verify'), ['phone' => $user->phone, 'code' => '111111'])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->fresh()->phone_verified_at);
        $this->assertSame('pending', $organization->fresh()->status);
        $this->assertGuest();
    }

    public function test_development_code_verifies_a_pending_pharmacy_registration_without_sending_an_sms(): void
    {
        config()->set('auth.development_otp_code', '000000');
        Http::preventStrayRequests();
        $organization = Organization::factory()->pending()->create(['phone' => '+992901234567']);
        $user = User::factory()->pharmacy($organization)->create(['phone' => '+992901234567']);

        $this->post(route('register'), ['phone' => $user->phone])->assertRedirect(route('register.otp.form'));
        $this->post(route('register.otp.verify'), ['phone' => $user->phone, 'code' => '000000'])
            ->assertRedirect(route('subscription.create'));

        $this->assertNotNull($user->fresh()->phone_verified_at);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('one_time_passwords', 0);
    }

    public function test_registration_routes_existing_pending_unverified_accounts_to_registration_otp(): void
    {
        Http::fake(['https://api.osonsms.com/sendsms_v1.php*' => Http::response(['status' => 'success', 'transaction_id' => 'provider-1'])]);
        $organization = Organization::factory()->pending()->create(['phone' => '+992901234567']);
        $user = User::factory()->pharmacy($organization)->create(['phone' => '+992901234567']);

        $this->post(route('register'), ['phone' => '901234567'])->assertRedirect(route('register.otp.form'));

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('one_time_passwords', ['purpose' => 'registration', 'phone' => $user->phone, 'status' => 'sent']);
    }

    public function test_registration_routes_pending_verified_active_and_blocked_accounts_to_normal_login_without_changing_their_plan(): void
    {
        Http::fake(['https://api.osonsms.com/sendsms_v1.php*' => Http::response(['status' => 'success', 'transaction_id' => 'provider-1'])]);
        $pendingOrganization = Organization::factory()->pending()->create(['phone' => '+992901234567']);
        $pendingUser = User::factory()->pharmacy($pendingOrganization)->create(['phone' => '+992901234567', 'phone_verified_at' => now(), 'subscription_plan' => SubscriptionPlan::Base]);
        $activeOrganization = Organization::factory()->pharmacy()->create(['phone' => '+992901234568']);
        $activeUser = User::factory()->pharmacy($activeOrganization)->create(['phone' => '+992901234568', 'subscription_plan' => SubscriptionPlan::Premium]);
        $blockedOrganization = Organization::factory()->pharmacy()->create(['phone' => '+992901234569']);
        $blockedUser = User::factory()->pharmacy($blockedOrganization)->create(['phone' => '+992901234569', 'is_blocked' => true]);

        foreach ([$pendingUser, $activeUser, $blockedUser] as $user) {
            $this->post(route('register'), ['phone' => $user->phone])->assertRedirect(route('login.otp.form'));
        }

        $this->assertSame(SubscriptionPlan::Base, $pendingUser->fresh()->subscription_plan);
        $this->assertSame(SubscriptionPlan::Premium, $activeUser->fresh()->subscription_plan);
        $this->assertTrue($blockedUser->fresh()->is_blocked);
        $this->assertDatabaseCount('organizations', 3);
    }

    public function test_pharmacy_can_log_in_with_a_valid_single_use_code(): void
    {
        $organization = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($organization)->create(['phone' => '+992901234567', 'password' => null, 'email' => null]);
        OneTimePassword::factory()->create(['phone' => $user->phone, 'code_hash' => Hash::make('123456')]);

        $this->withSession(['phone_otp.login' => $user->phone])->post(route('login.otp.verify'), ['phone' => $user->phone, 'code' => '123456'])->assertRedirect(route('catalog'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(OneTimePassword::first()->consumed_at);
    }

    public function test_phone_login_preserves_an_intended_destination_for_a_pharmacy(): void
    {
        $user = User::factory()->pharmacy()->create(['phone' => '+992901234567', 'password' => null, 'email' => null]);
        OneTimePassword::factory()->create(['phone' => $user->phone, 'code_hash' => Hash::make('123456')]);

        $response = $this->withSession([
            'phone_otp.login' => $user->phone,
            'url.intended' => route('orders.index'),
        ])->post(route('login.otp.verify'), ['phone' => $user->phone, 'code' => '123456']);

        $response->assertRedirect(route('orders.index'));
    }

    public function test_second_device_login_shows_confirmation_and_cancel_keeps_original_session(): void
    {
        $this->useDatabaseSessions();
        $this->travelTo('2026-09-11 09:00:00');
        $organization = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($organization)->create(['phone' => '+992901234567', 'password' => null, 'email' => null]);
        OneTimePassword::factory()->create(['phone' => $user->phone, 'code_hash' => Hash::make('123456')]);
        $rawUserAgent = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:143.0) Gecko/20100101 Firefox/143.0';
        $this->createActiveSession($user, 'other-device-session', $rawUserAgent, '203.0.113.10', now()->subMinutes(10)->timestamp);

        $response = $this->withSession(['phone_otp.login' => $user->phone])
            ->post(route('login.otp.verify'), ['phone' => $user->phone, 'code' => '123456']);

        $response->assertRedirectToRoute('login.session.confirmation');
        $this->assertGuest();
        $this->assertDatabaseHas('sessions', ['id' => 'other-device-session', 'user_id' => $user->id]);

        $sessionId = $response->getCookie(config('session.cookie'), false)->getValue();
        $this->withUnencryptedCookie(config('session.cookie'), $sessionId)
            ->get(route('login.session.confirmation'))
            ->assertViewIs('auth.session-confirmation')
            ->assertSee('Активный сеанс на другом устройстве')
            ->assertSee('OAPTEKA не завершает другие сеансы автоматически')
            ->assertSee('Firefox · Ubuntu')
            ->assertDontSee($rawUserAgent)
            ->assertSee('203.0.113.10')
            ->assertSee('11.09.2026 08:50')
            ->assertSee('action="'.route('login.session.cancel').'"', false)
            ->assertSee('action="'.route('login.session.confirm').'"', false)
            ->assertSee('Назад ко входу')
            ->assertSee('Выйти с другого устройства и войти');
        $this->withUnencryptedCookie(config('session.cookie'), $sessionId)
            ->post(route('login.session.cancel'))
            ->assertRedirectToRoute('login')
            ->assertSessionHas('warning');

        $this->assertGuest();
        $this->assertDatabaseHas('sessions', ['id' => 'other-device-session', 'user_id' => $user->id]);
        $this->travelBack();
    }

    public function test_confirming_second_device_login_invalidates_existing_session_and_authenticates_current_device(): void
    {
        $this->useDatabaseSessions();
        $this->travelTo('2026-09-11 09:00:00');
        $organization = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($organization)->create(['phone' => '+992901234567', 'password' => null, 'email' => null]);
        OneTimePassword::factory()->create(['phone' => $user->phone, 'code_hash' => Hash::make('123456')]);
        $this->createActiveSession($user, 'other-device-session', 'Firefox 143 / Ubuntu 24.04', '203.0.113.10', now()->subMinutes(10)->timestamp);

        $otpResponse = $this->withSession([
            'phone_otp.login' => $user->phone,
            'url.intended' => route('orders.index'),
        ])
            ->post(route('login.otp.verify'), ['phone' => $user->phone, 'code' => '123456']);
        $otpResponse->assertRedirectToRoute('login.session.confirmation');
        $sessionId = $otpResponse->getCookie(config('session.cookie'), false)->getValue();

        $this->withUnencryptedCookie(config('session.cookie'), $sessionId)
            ->post(route('login.session.confirm'))
            ->assertRedirectToRoute('catalog');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
        app()->forgetInstance('auth.driver');
        app()->forgetInstance('auth');
        app()->forgetInstance('session.store');
        app()->forgetInstance('session');
        Facade::clearResolvedInstances();
        $this->withUnencryptedCookie(config('session.cookie'), $this->encryptedSessionCookie('other-device-session'))
            ->get(route('dashboard'))
            ->assertRedirectToRoute('login');
        $this->travelBack();
    }

    public function test_supplier_session_confirmation_uses_supplier_routes_and_cancel_keeps_original_session(): void
    {
        $this->useDatabaseSessions();
        $this->travelTo('2026-09-11 09:00:00');
        $supplier = User::factory()->wholesaler()->create(['phone' => '+992901234567', 'password' => null]);
        OneTimePassword::factory()->create(['account_type' => UserRole::Wholesaler, 'phone' => $supplier->phone, 'code_hash' => Hash::make('123456')]);
        $this->createActiveSession($supplier, 'supplier-device-session', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0', '198.51.100.20', now()->subMinutes(5)->timestamp);

        $otpResponse = $this->withSession(['phone_otp.supplier_login' => $supplier->phone])
            ->post(route('provider.otp.verify'), ['phone' => $supplier->phone, 'code' => '123456']);

        $otpResponse->assertRedirectToRoute('provider.session.confirmation');
        $sessionId = $otpResponse->getCookie(config('session.cookie'), false)->getValue();

        $this->withUnencryptedCookie(config('session.cookie'), $sessionId)
            ->get(route('provider.session.confirmation'))
            ->assertViewIs('auth.session-confirmation')
            ->assertSee('Edge · Windows')
            ->assertSee('198.51.100.20')
            ->assertSee('11.09.2026 08:55')
            ->assertSee('action="'.route('provider.session.cancel').'"', false)
            ->assertSee('action="'.route('provider.session.confirm').'"', false)
            ->assertDontSee('action="'.route('login.session.confirm').'"', false);

        $this->withUnencryptedCookie(config('session.cookie'), $sessionId)
            ->post(route('provider.session.cancel'))
            ->assertRedirectToRoute('provider.login')
            ->assertSessionHas('warning');

        $this->assertGuest();
        $this->assertDatabaseHas('sessions', ['id' => 'supplier-device-session', 'user_id' => $supplier->id]);
        $this->travelBack();
    }

    public function test_confirming_supplier_session_replacement_preserves_the_intended_destination(): void
    {
        $this->useDatabaseSessions();
        $supplier = User::factory()->wholesaler()->create(['phone' => '+992901234567', 'password' => null]);
        OneTimePassword::factory()->create(['account_type' => UserRole::Wholesaler, 'phone' => $supplier->phone, 'code_hash' => Hash::make('123456')]);
        $this->createActiveSession($supplier, 'supplier-device-session', 'Custom Client', '198.51.100.20', now()->subMinutes(5)->timestamp);

        $otpResponse = $this->withSession([
            'phone_otp.supplier_login' => $supplier->phone,
            'url.intended' => route('orders.index'),
        ])->post(route('provider.otp.verify'), ['phone' => $supplier->phone, 'code' => '123456']);
        $sessionId = $otpResponse->getCookie(config('session.cookie'), false)->getValue();

        $this->withUnencryptedCookie(config('session.cookie'), $sessionId)
            ->post(route('provider.session.confirm'))
            ->assertRedirect(route('orders.index'));

        $this->assertAuthenticatedAs($supplier);
        $this->assertDatabaseMissing('sessions', ['id' => 'supplier-device-session']);
    }

    public function test_wrong_codes_are_limited_to_five_attempts(): void
    {
        $otp = OneTimePassword::factory()->create(['phone' => '+992901234567', 'code_hash' => Hash::make('123456')]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withSession(['phone_otp.login' => $otp->phone])->post(route('login.otp.verify'), ['phone' => $otp->phone, 'code' => '111111'])->assertSessionHasErrors('code');
        }
        $this->assertSame(5, $otp->fresh()->attempts);
        $this->withSession(['phone_otp.login' => $otp->phone])->post(route('login.otp.verify'), ['phone' => $otp->phone, 'code' => '123456'])->assertSessionHasErrors('code');
    }

    public function test_supplier_phone_login_authenticates_only_supplier_accounts(): void
    {
        $provider = User::factory()->wholesaler()->create(['phone' => '+992901234567', 'password' => null]);
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com', 'password' => 'password']);
        $pharmacy = User::factory()->pharmacy()->create(['phone' => '+992901234568']);
        OneTimePassword::factory()->create(['account_type' => UserRole::Wholesaler, 'phone' => $provider->phone, 'code_hash' => Hash::make('123456')]);

        $this->withSession(['phone_otp.supplier_login' => $provider->phone])
            ->post(route('provider.otp.verify'), ['phone' => $provider->phone, 'code' => '123456'])
            ->assertRedirect(route('dashboard'));
        $this->assertNotNull($provider->fresh()->phone_verified_at);
        auth()->logout();
        $this->withSession(['phone_otp.supplier_login' => $pharmacy->phone])
            ->post(route('provider.otp.verify'), ['phone' => $pharmacy->phone, 'code' => '123456'])
            ->assertSessionHasErrors('code');
        $this->post(route('admin.login.authenticate'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('two-factor.enroll'));
    }

    public function test_buyer_mode_supplier_phone_login_defaults_to_catalog(): void
    {
        $provider = User::factory()->wholesaler()->create([
            'active_trade_mode' => TradeMode::Buyer,
            'phone' => '+992901234567',
            'password' => null,
        ]);
        OneTimePassword::factory()->create(['account_type' => UserRole::Wholesaler, 'phone' => $provider->phone, 'code_hash' => Hash::make('123456')]);

        $response = $this->withSession(['phone_otp.supplier_login' => $provider->phone])
            ->post(route('provider.otp.verify'), ['phone' => $provider->phone, 'code' => '123456']);

        $response->assertRedirect(route('catalog'));
        $this->assertAuthenticatedAs($provider);
    }

    public function test_unknown_supplier_phone_creates_a_pending_supplier_after_company_name_is_provided(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.osonsms.com/sendsms_v1.php*' => Http::response(['status' => 'success', 'transaction_id' => 'supplier-1'])]);

        $this->post(route('provider.register.phone'), ['phone' => '901234567'])
            ->assertRedirect(route('provider.register.details.form'));
        $this->post(route('provider.register.details.store'), ['supplier_name' => 'Поставщик Тест'])
            ->assertRedirect(route('provider.register.otp.form'));

        $this->assertDatabaseHas('organizations', ['name' => 'Поставщик Тест', 'phone' => '+992901234567', 'type' => 'wholesaler', 'supplier_mode' => 'supplier', 'status' => 'pending']);
        $this->assertDatabaseHas('users', ['name' => 'Поставщик Тест', 'phone' => '+992901234567', 'role' => 'wholesaler', 'password' => null]);
    }

    public function test_admin_approval_activates_verified_supplier_without_changing_subscription_plan(): void
    {
        $organization = Organization::factory()->wholesaler()->pending()->create();
        $supplier = User::factory()->wholesaler($organization)->create(['phone_verified_at' => now(), 'subscription_plan' => SubscriptionPlan::Premium]);
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($admin)->post(route('admin.approve', $organization))->assertRedirect();

        $this->assertSame('active', $organization->fresh()->status);
        $this->assertNotNull($supplier->fresh()->approved_at);
        $this->assertSame(SubscriptionPlan::Premium, $supplier->fresh()->subscription_plan);
    }

    public function test_admin_password_login_bypasses_two_factor_in_the_local_environment(): void
    {
        app()->detectEnvironment(static fn (): string => 'local');
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com', 'password' => 'password']);

        try {
            $this->withoutMiddleware(PreventRequestForgery::class)
                ->post(route('admin.login.authenticate'), ['email' => $admin->email, 'password' => 'password'])
                ->assertRedirect(route('dashboard'));
        } finally {
            app()->detectEnvironment(static fn (): string => 'testing');
        }
    }

    public function test_unconfirmed_admin_can_access_the_dashboard_in_the_local_environment(): void
    {
        app()->detectEnvironment(static fn (): string => 'local');
        $admin = User::factory()->admin()->create();

        try {
            $this->actingAs($admin)->get(route('dashboard'))->assertSee('Панель администратора');
        } finally {
            app()->detectEnvironment(static fn (): string => 'testing');
        }
    }

    public function test_development_code_logs_in_a_pharmacy_by_phone_without_sending_an_sms(): void
    {
        config()->set('auth.development_otp_code', '000000');
        $pharmacy = User::factory()->pharmacy()->create(['phone' => '+992901234567']);

        $this->withSession(['phone_otp.login' => $pharmacy->phone])->post(route('login.otp.verify'), ['phone' => $pharmacy->phone, 'code' => '000000'])->assertRedirect(route('catalog'));

        $this->assertAuthenticatedAs($pharmacy);
    }

    public function test_phone_login_rejects_a_provider_even_with_the_development_code(): void
    {
        config()->set('auth.development_otp_code', '000000');
        $provider = User::factory()->wholesaler()->create(['phone' => '+992901234567']);

        $this->withSession(['phone_otp.login' => $provider->phone])
            ->post(route('login.otp.verify'), ['phone' => $provider->phone, 'code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    private function useDatabaseSessions(): void
    {
        config()->set('session.driver', 'database');
        app('session')->forgetDrivers();
    }

    private function createActiveSession(User $user, string $sessionId, string $userAgent, string $ipAddress, int $lastActivity): void
    {
        DB::table(config('session.table'))->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'payload' => base64_encode(serialize([auth()->guard()->getName() => $user->getAuthIdentifier()])),
            'last_activity' => $lastActivity,
        ]);
    }

    private function encryptedSessionCookie(string $sessionId): string
    {
        return encrypt(CookieValuePrefix::create(config('session.cookie'), app('encrypter')->getKey()).$sessionId, false);
    }
}
