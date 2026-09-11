<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentMethodSetting;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentRequestReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pharmacy_submits_immutable_receipt_backed_quote_and_admin_approves_it(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00', 'Asia/Dushanbe'));
        Storage::fake('local');
        $pharmacy = User::factory()->pharmacy()->create();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        SubscriptionPlanPrice::create(['plan' => SubscriptionPlan::Base, 'daily_price' => '1.00']);
        PaymentMethodSetting::create(['method' => PaymentMethod::Dc, 'is_enabled' => true, 'wallet_number' => '+992901234567', 'wallet_owner_name' => 'Акмал Гаффоров']);

        $this->actingAs($pharmacy)->post(route('subscription.requests.store'), ['plan' => 'base', 'amount' => '91.00', 'payment_method' => 'dc', 'sender_wallet_number' => '+992901234568', 'sender_wallet_owner_name' => 'Аптека Тест', 'transferred_on' => '01/09/2026', 'receipt' => UploadedFile::fake()->image('receipt.jpg')])->assertRedirect(route('subscription.create'));

        $payment = PaymentRequest::firstOrFail();
        $subscription = Subscription::firstOrFail();
        $this->assertSame('91.00', $payment->amount);
        $this->assertSame(91, $payment->days);
        $this->assertSame('+992901234567', $payment->recipient_wallet);
        $this->assertSame('Акмал Гаффоров', $payment->recipient_wallet_owner_name);
        $this->assertSame('+992901234568', $payment->sender_wallet_number);
        $this->assertSame('Аптека Тест', $payment->sender_wallet_owner_name);
        Storage::disk('local')->assertExists($payment->receipt_path);

        SubscriptionPlanPrice::query()->where('plan', 'base')->update(['daily_price' => '99.00']);
        $this->actingAs($admin)->post(route('admin.subscription-payments.review', $payment), ['decision' => 'approve', 'verified_amount' => '91.00', 'verified_reference' => 'wallet-9'])->assertRedirect();

        $this->assertSame('approved', $payment->refresh()->status);
        $this->assertSame('2026-09-01', $subscription->refresh()->starts_on->toDateString());
        $this->assertSame('2026-11-30', $subscription->ends_on->toDateString());
        $this->assertSame('91.00', $subscription->total_price);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(SubscriptionPlan::Base, $pharmacy->refresh()->subscription_plan);
        $this->assertDatabaseHas('audit_events', ['event' => 'payment_request.approved', 'subject_id' => $payment->id]);
    }

    public function test_review_rejects_mismatch_and_rejection_allows_resubmission(): void
    {
        Storage::fake('local');
        $pharmacy = User::factory()->pharmacy()->create();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        SubscriptionPlanPrice::create(['plan' => SubscriptionPlan::Base, 'daily_price' => '1.00']);
        PaymentMethodSetting::create(['method' => PaymentMethod::Alif, 'is_enabled' => true, 'wallet_number' => '+992901234568', 'wallet_owner_name' => 'Акмал Гаффоров']);
        $payload = ['plan' => 'base', 'amount' => '91.00', 'payment_method' => 'alif', 'sender_wallet_number' => '+992901234568', 'transferred_on' => now()->format('d/m/Y'), 'receipt' => UploadedFile::fake()->image('receipt.jpg')];

        $this->actingAs($pharmacy)->post(route('subscription.requests.store'), $payload)->assertRedirect();
        $payment = PaymentRequest::firstOrFail();
        $this->actingAs($admin)->post(route('admin.subscription-payments.review', $payment), ['decision' => 'approve', 'verified_amount' => '1.00', 'verified_reference' => 'wrong'])->assertSessionHasErrors('payment_request');
        $this->assertSame('pending', $payment->fresh()->status);

        $this->actingAs($admin)->post(route('admin.subscription-payments.review', $payment), ['decision' => 'reject', 'rejection_reason' => 'Сумма не найдена в кошельке.'])->assertRedirect();
        $this->assertSame('rejected', $payment->refresh()->status);
        $this->assertSame(SubscriptionStatus::Rejected, Subscription::firstOrFail()->refresh()->status);

        $payload['receipt'] = UploadedFile::fake()->image('receipt-2.jpg');
        $this->actingAs($pharmacy)->post(route('subscription.requests.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('payment_requests', 2);
    }

    public function test_receipt_is_private_to_the_submitting_organization_and_admins(): void
    {
        Storage::fake('local');
        $pharmacy = User::factory()->pharmacy()->create();
        $otherPharmacy = User::factory()->pharmacy()->create();
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $payment = PaymentRequest::create(['organization_id' => $pharmacy->organization_id, 'user_id' => $pharmacy->id, 'days' => 90, 'amount' => '90.00', 'receipt_path' => 'payment-receipts/receipt.pdf', 'status' => 'pending']);
        Storage::disk('local')->put($payment->receipt_path, 'receipt');

        $this->actingAs($otherPharmacy)->get(route('payment-requests.receipt', $payment))->assertForbidden();
        $this->actingAs($pharmacy)->get(route('payment-requests.receipt', $payment))->assertOk();
        $this->actingAs($admin)->get(route('payment-requests.receipt', $payment))->assertOk();
    }

    public function test_admin_can_configure_only_the_fixed_payment_methods(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($admin)->get(route('admin.subscription-payments.index'))
            ->assertSee('Номер таджикского кошелька')
            ->assertDontSee('Инструкция на русском')
            ->assertDontSee('wallet_number" />');

        $this->actingAs($admin)->patch(route('admin.subscription-payments.methods.update'), ['methods' => [
            ['method' => 'dc', 'is_enabled' => '1', 'wallet_number' => '+992901234567', 'wallet_owner_name' => 'Акмал Гаффоров'],
            ['method' => 'eskhata_online', 'is_enabled' => '0', 'wallet_number' => '', 'wallet_owner_name' => ''],
            ['method' => 'alif', 'is_enabled' => '0', 'wallet_number' => '', 'wallet_owner_name' => ''],
        ]])->assertRedirect();

        $this->assertDatabaseHas('payment_method_settings', ['method' => 'dc', 'is_enabled' => true, 'wallet_number' => '+992901234567', 'wallet_owner_name' => 'Акмал Гаффоров']);
        $this->assertDatabaseHas('audit_events', ['event' => 'payment_method.updated']);
        $this->actingAs($admin)->patch(route('admin.subscription-payments.methods.update'), ['methods' => [['method' => 'other', 'is_enabled' => '1', 'wallet_number' => '+992901234567', 'wallet_owner_name' => 'Акмал Гаффоров']]])->assertSessionHasErrors('methods.0.method');
    }

    public function test_submission_rejects_unavailable_methods_and_oversized_receipts(): void
    {
        Storage::fake('local');
        $pharmacy = User::factory()->pharmacy()->create();
        SubscriptionPlanPrice::create(['plan' => SubscriptionPlan::Base, 'daily_price' => '1.00']);
        $payload = ['plan' => 'base', 'amount' => '91.00', 'payment_method' => 'dc', 'transferred_on' => now()->format('d/m/Y'), 'receipt' => UploadedFile::fake()->image('receipt.jpg')];

        $this->actingAs($pharmacy)->post(route('subscription.requests.store'), $payload)->assertSessionHasErrors('payment_method');
        PaymentMethodSetting::create(['method' => PaymentMethod::Dc, 'is_enabled' => true, 'wallet_number' => '+992901234567', 'wallet_owner_name' => 'Акмал Гаффоров']);
        $payload['receipt'] = UploadedFile::fake()->create('receipt.pdf', 10241, 'application/pdf');
        $this->actingAs($pharmacy)->post(route('subscription.requests.store'), $payload)->assertSessionHasErrors('receipt');
        $this->assertDatabaseCount('payment_requests', 0);
    }
}
