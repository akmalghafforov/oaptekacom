<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OneTimePassword;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneOtpAuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

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

    public function test_wrong_codes_are_limited_to_five_attempts(): void
    {
        $otp = OneTimePassword::factory()->create(['phone' => '+992901234567', 'code_hash' => Hash::make('123456')]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withSession(['phone_otp.login' => $otp->phone])->post(route('login.otp.verify'), ['phone' => $otp->phone, 'code' => '111111'])->assertSessionHasErrors('code');
        }
        $this->assertSame(5, $otp->fresh()->attempts);
        $this->withSession(['phone_otp.login' => $otp->phone])->post(route('login.otp.verify'), ['phone' => $otp->phone, 'code' => '123456'])->assertSessionHasErrors('code');
    }

    public function test_staff_password_login_remains_available_and_pharmacy_password_login_is_rejected(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Wholesaler, 'email' => 'staff@example.com', 'password' => 'password']);
        $pharmacy = User::factory()->create(['role' => UserRole::Pharmacy, 'email' => 'pharmacy@example.com', 'password' => 'password']);

        $this->post(route('login'), ['email' => $staff->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
        auth()->logout();
        $this->post(route('login'), ['email' => $pharmacy->email, 'password' => 'password'])->assertSessionHasErrors('email');
    }

    public function test_development_code_logs_in_any_role_by_phone_without_sending_an_sms(): void
    {
        config()->set('auth.development_otp_code', '000000');
        $staff = User::factory()->create(['role' => UserRole::Wholesaler, 'phone' => '+992901234567']);

        $this->withSession(['phone_otp.login' => $staff->phone])->post(route('login.otp.verify'), ['phone' => $staff->phone, 'code' => '000000'])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($staff);
    }
}
