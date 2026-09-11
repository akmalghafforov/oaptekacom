<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Organization;
use App\Models\PaymentMethodSetting;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SubscriptionAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_active_pharmacy_without_a_paid_subscription_is_restricted_to_the_subscription_page(): void
    {
        $organization = Organization::factory()->pharmacy()->create(['subscription_until' => now()->subDay()]);
        $user = User::factory()->pharmacy($organization)->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('subscription.create'));
        $this->actingAs($user)->get(route('subscription.create'))->assertOk();
    }

    public function test_non_admin_cannot_manage_subscriptions(): void
    {
        $user = User::factory()->pharmacy()->create();

        $this->actingAs($user)->get(route('admin.subscriptions.index'))->assertForbidden();
    }

    public function test_subscription_checkout_renders_fixed_terms_with_the_month_selected_by_default(): void
    {
        $user = User::factory()->pharmacy()->create();
        SubscriptionPlanPrice::create(['plan' => SubscriptionPlan::Base, 'daily_price' => '1.00']);
        PaymentMethodSetting::create(['method' => PaymentMethod::Dc, 'is_enabled' => true, 'wallet_number' => '+992901234567', 'wallet_owner_name' => 'Акмал Гаффоров']);

        $this->actingAs($user)->get(route('subscription.create'))
            ->assertSee('Срок подписки')
            ->assertSee(['10 дней', '1 месяц', '6 месяцев', '1 год'])
            ->assertSeeHtml('value="month" data-subscription-term data-days="30" checked')
            ->assertSeeHtml('data-subscription-total')
            ->assertSeeHtml('name="receipt"')
            ->assertDontSee('Ваш кошелёк отправителя')
            ->assertDontSee('Имя владельца кошелька отправителя')
            ->assertDontSeeHtml('data-subscription-amount');
    }

    public function test_active_base_subscription_hides_the_purchase_form(): void
    {
        $user = User::factory()->pharmacy()->create(['subscription_plan' => SubscriptionPlan::Base]);
        Subscription::factory()->for($user)->create([
            'plan' => SubscriptionPlan::Base,
            'status' => SubscriptionStatus::Active,
            'starts_on' => now('Asia/Dushanbe')->subDay(),
            'ends_on' => now('Asia/Dushanbe')->addDay(),
        ]);

        $this->actingAs($user)->get(route('subscription.create'))
            ->assertSee('Подписка действует по')
            ->assertDontSee('Оплата подписки')
            ->assertDontSeeHtml('data-subscription-calculator');
    }
}
