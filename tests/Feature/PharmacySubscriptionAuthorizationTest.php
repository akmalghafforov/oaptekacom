<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTerm;
use App\Models\Organization;
use App\Models\PaymentMethodSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PharmacySubscriptionAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pending_payment_request_blocks_pharmacy_until_an_admin_confirms_it(): void
    {
        Storage::fake('local');
        $pharmacy = User::factory()->pharmacy()->create();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        SubscriptionPlanPrice::create(['plan' => SubscriptionPlan::Base, 'daily_price' => '1.00']);
        PaymentMethodSetting::create(['method' => PaymentMethod::Dc, 'is_enabled' => true, 'wallet_number' => '+992901234567', 'wallet_owner_name' => 'Акмал Гаффоров']);

        $this->actingAs($pharmacy)
            ->post(route('subscription.requests.store'), ['plan' => SubscriptionPlan::Base->value, 'term' => SubscriptionTerm::Month->value, 'payment_method' => 'dc', 'transferred_on' => now()->format('d/m/Y'), 'receipt' => UploadedFile::fake()->image('receipt.jpg')])
            ->assertRedirect(route('subscription.create'));
        $subscription = Subscription::firstOrFail();
        $this->assertSame(SubscriptionStatus::Pending, $subscription->status);
        $this->actingAs($pharmacy)->get(route('dashboard'))->assertRedirect(route('subscription.create'));

        $this->actingAs($admin)
            ->post(route('admin.subscription-payments.review', $subscription->paymentRequest), ['decision' => 'approve', 'verified_amount' => $subscription->total_price, 'verified_reference' => 'wallet-1'])
            ->assertRedirect();
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->actingAs($pharmacy)->get(route('dashboard'))->assertOk();
    }

    public function test_cancelled_subscription_immediately_restricts_only_the_pharmacy_identity(): void
    {
        $phone = '+992901234567';
        $pharmacy = User::factory()->pharmacy(Organization::factory()->pharmacy()->create(['phone' => $phone]))->create(['phone' => $phone, 'subscription_plan' => SubscriptionPlan::Base]);
        $supplier = User::factory()->wholesaler(Organization::factory()->wholesaler()->create(['phone' => $phone]))->create(['phone' => $phone]);
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $subscription = Subscription::factory()->for($pharmacy)->create(['plan' => SubscriptionPlan::Base, 'status' => SubscriptionStatus::Active, 'starts_on' => now('Asia/Dushanbe')->subDay(), 'ends_on' => now('Asia/Dushanbe')->addDay()]);

        $this->actingAs($admin)->post(route('admin.subscriptions.cancel', $subscription))->assertRedirect();

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->refresh()->status);
        $this->actingAs($pharmacy)->get(route('dashboard'))->assertRedirect(route('subscription.create'));
        $this->actingAs($supplier)->get(route('dashboard'))->assertOk();
    }
}
