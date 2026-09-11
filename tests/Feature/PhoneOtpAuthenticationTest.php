<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OneTimePassword;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneOtpAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

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

    public function test_registration_creates_a_pharmacy_only_after_phone_verification(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.osonsms.com/sendsms_v1.php*' => Http::response(['status' => 'success', 'transaction_id' => 'provider-1'])]);

        $this->post(route('register'), ['pharmacy_name' => 'Аптека Тест', 'phone' => '901234567'])->assertRedirect(route('register.otp.form'));
        $this->assertDatabaseCount('users', 0);
        $otp = OneTimePassword::firstOrFail();
        $otp->update(['code_hash' => Hash::make('123456')]);

        $this->post(route('register.otp.verify'), ['phone' => '+992901234567', 'code' => '123456'])->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', ['phone' => '+992901234567', 'role' => 'pharmacy', 'password' => null]);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.osonsms.com/sendsms_v1.php'));
    }

    public function test_pharmacy_can_log_in_with_a_valid_single_use_code(): void
    {
        $organization = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($organization)->create(['phone' => '+992901234567', 'password' => null, 'email' => null]);
        OneTimePassword::factory()->create(['phone' => $user->phone, 'code_hash' => Hash::make('123456')]);

        $this->withSession(['phone_otp.login' => $user->phone])->post(route('login.otp.verify'), ['phone' => $user->phone, 'code' => '123456'])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(OneTimePassword::first()->consumed_at);
    }

    public function test_second_device_login_shows_confirmation_and_cancel_keeps_original_session(): void
    {
        $this->useDatabaseSessions();
        $this->travelTo('2026-09-11 09:00:00');
        $organization = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($organization)->create(['phone' => '+992901234567', 'password' => null, 'email' => null]);
        OneTimePassword::factory()->create(['phone' => $user->phone, 'code_hash' => Hash::make('123456')]);
        $this->createActiveSession($user, 'other-device-session', 'Firefox 143 / Ubuntu 24.04', '203.0.113.10', now()->subMinutes(10)->timestamp);

        $response = $this->withSession(['phone_otp.login' => $user->phone])
            ->post(route('login.otp.verify'), ['phone' => $user->phone, 'code' => '123456']);

        $response->assertRedirectToRoute('login.session.confirmation');
        $this->assertGuest();
        $this->assertDatabaseHas('sessions', ['id' => 'other-device-session', 'user_id' => $user->id]);

        $sessionId = $response->getCookie(config('session.cookie'), false)->getValue();
        $this->withUnencryptedCookie(config('session.cookie'), $sessionId)
            ->get(route('login.session.confirmation'))
            ->assertViewIs('auth.session-confirmation')
            ->assertSee('Firefox 143 / Ubuntu 24.04')
            ->assertSee('203.0.113.10')
            ->assertSee('11.09.2026 08:50');
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

        $otpResponse = $this->withSession(['phone_otp.login' => $user->phone])
            ->post(route('login.otp.verify'), ['phone' => $user->phone, 'code' => '123456']);
        $otpResponse->assertRedirectToRoute('login.session.confirmation');
        $sessionId = $otpResponse->getCookie(config('session.cookie'), false)->getValue();

        $this->withUnencryptedCookie(config('session.cookie'), $sessionId)
            ->post(route('login.session.confirm'))
            ->assertRedirectToRoute('dashboard');

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

    public function test_wrong_codes_are_limited_to_five_attempts(): void
    {
        $otp = OneTimePassword::factory()->create(['phone' => '+992901234567', 'code_hash' => Hash::make('123456')]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withSession(['phone_otp.login' => $otp->phone])->post(route('login.otp.verify'), ['phone' => $otp->phone, 'code' => '111111'])->assertSessionHasErrors('code');
        }
        $this->assertSame(5, $otp->fresh()->attempts);
        $this->withSession(['phone_otp.login' => $otp->phone])->post(route('login.otp.verify'), ['phone' => $otp->phone, 'code' => '123456'])->assertSessionHasErrors('code');
    }

    public function test_provider_and_admin_password_login_pages_only_authenticate_their_own_roles(): void
    {
        $provider = User::factory()->wholesaler()->create(['email' => 'provider@example.com', 'password' => 'password']);
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com', 'password' => 'password']);
        $pharmacy = User::factory()->create(['role' => UserRole::Pharmacy, 'email' => 'pharmacy@example.com', 'password' => 'password']);

        $this->post(route('provider.login.authenticate'), ['email' => $provider->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
        auth()->logout();
        $this->post(route('admin.login.authenticate'), ['email' => $provider->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post(route('provider.login.authenticate'), ['email' => $pharmacy->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post(route('admin.login.authenticate'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('two-factor.enroll'));
    }

    public function test_development_code_logs_in_a_pharmacy_by_phone_without_sending_an_sms(): void
    {
        config()->set('auth.development_otp_code', '000000');
        $pharmacy = User::factory()->pharmacy()->create(['phone' => '+992901234567']);

        $this->withSession(['phone_otp.login' => $pharmacy->phone])->post(route('login.otp.verify'), ['phone' => $pharmacy->phone, 'code' => '000000'])->assertRedirect(route('dashboard'));

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
