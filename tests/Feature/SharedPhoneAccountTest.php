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

class SharedPhoneAccountTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_supplier_registration_accepts_a_phone_owned_by_a_pharmacy(): void
    {
        Http::fake(['https://api.osonsms.com/sendsms_v1.php*' => Http::response(['status' => 'success', 'transaction_id' => 'supplier-1'])]);
        $phone = '+992901234567';
        $pharmacyOrganization = Organization::factory()->pharmacy()->create(['phone' => $phone]);
        $pharmacy = User::factory()->pharmacy($pharmacyOrganization)->create(['phone' => $phone]);

        $this->post(route('provider.register.phone'), ['phone' => $phone])
            ->assertRedirect(route('provider.register.details.form'));
        $this->post(route('provider.register.details.store'), ['supplier_name' => 'Поставщик Тест'])
            ->assertRedirect(route('provider.register.otp.form'));

        $supplier = User::query()->where('phone', $phone)->where('role', UserRole::Wholesaler)->firstOrFail();
        $this->assertNotSame($pharmacy->id, $supplier->id);
        $this->assertNotSame($pharmacy->organization_id, $supplier->organization_id);
    }

    public function test_otp_cannot_cross_authenticate_same_phone_accounts(): void
    {
        $phone = '+992901234567';
        $pharmacy = User::factory()->pharmacy(Organization::factory()->pharmacy()->create(['phone' => $phone]))->create(['phone' => $phone]);
        $supplier = User::factory()->wholesaler(Organization::factory()->wholesaler()->create(['phone' => $phone]))->create(['phone' => $phone]);
        OneTimePassword::factory()->create(['account_type' => UserRole::Pharmacy, 'phone' => $phone, 'code_hash' => Hash::make('123456')]);
        OneTimePassword::factory()->create(['account_type' => UserRole::Wholesaler, 'phone' => $phone, 'code_hash' => Hash::make('654321')]);

        $this->withSession(['phone_otp.supplier_login' => $phone])
            ->post(route('provider.otp.verify'), ['phone' => $phone, 'code' => '123456'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->withSession(['phone_otp.supplier_login' => $phone])
            ->post(route('provider.otp.verify'), ['phone' => $phone, 'code' => '654321'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($supplier);
        $this->assertNotSame($pharmacy->id, auth()->id());
    }
}
