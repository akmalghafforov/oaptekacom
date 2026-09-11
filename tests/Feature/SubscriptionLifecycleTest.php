<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTerm;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_pharmacy_users_default_to_the_free_plan_enum(): void
    {
        $user = User::factory()->pharmacy()->create();

        $user->refresh();

        $this->assertSame(SubscriptionPlan::Free, $user->subscription_plan);
    }

    public function test_admin_cannot_approve_an_unverified_pending_pharmacy(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $organization = Organization::factory()->pending()->create();
        $pharmacy = User::factory()->pharmacy($organization)->create(['phone_verified_at' => null, 'subscription_plan' => SubscriptionPlan::Base]);

        $this->actingAs($admin)->post(route('admin.approve', $organization))->assertUnprocessable();

        $this->assertSame('pending', $organization->fresh()->status);
        $this->assertNull($pharmacy->fresh()->approved_at);
        $this->assertSame(SubscriptionPlan::Base, $pharmacy->fresh()->subscription_plan);
    }

    public function test_admin_approval_activates_a_verified_pending_pharmacy_and_assigns_the_free_plan(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $organization = Organization::factory()->pending()->create();
        $pharmacy = User::factory()->pharmacy($organization)->create(['phone_verified_at' => now(), 'subscription_plan' => SubscriptionPlan::Premium]);

        $this->actingAs($admin)->post(route('admin.approve', $organization))->assertRedirect();

        $this->assertSame('active', $organization->fresh()->status);
        $this->assertNotNull($pharmacy->fresh()->approved_at);
        $this->assertSame(SubscriptionPlan::Free, $pharmacy->fresh()->subscription_plan);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_admin_can_set_paid_plan_prices_and_zero_price_is_unavailable(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($admin)->patch(route('admin.subscription-prices.update'), ['base_daily_price' => '2.50', 'premium_daily_price' => '0'])->assertRedirect();

        $this->assertDatabaseHas('subscription_plan_prices', ['plan' => 'base', 'daily_price' => '2.50']);
        $this->assertDatabaseHas('subscription_plan_prices', ['plan' => 'premium', 'daily_price' => '0.00']);
    }

    public function test_grant_snapshots_calendar_period_price_and_audit_event(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00', 'Asia/Dushanbe'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = User::factory()->pharmacy()->create();
        SubscriptionPlanPrice::create(['plan' => SubscriptionPlan::Base, 'daily_price' => '2.50']);

        $this->actingAs($admin)->post(route('admin.subscriptions.store'), ['user_id' => $pharmacy->id, 'plan' => SubscriptionPlan::Base->value, 'term' => SubscriptionTerm::ThreeMonths->value])->assertRedirect(route('admin.subscriptions.index'));

        $subscription = Subscription::firstOrFail();
        $this->assertSame('2026-09-01', $subscription->starts_on->toDateString());
        $this->assertSame('2026-11-30', $subscription->ends_on->toDateString());
        $this->assertSame('2.50', $subscription->daily_price);
        $this->assertSame('227.50', $subscription->total_price);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(SubscriptionPlan::Base, $pharmacy->refresh()->subscription_plan);
        $this->assertDatabaseHas('audit_events', ['event' => 'subscription.granted', 'subject_id' => $subscription->id]);
    }

    #[DataProvider('presetTerms')]
    public function test_each_preset_term_uses_calendar_aware_inclusive_dates(string $term, string $endsOn): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00', 'Asia/Dushanbe'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = User::factory()->pharmacy()->create();
        SubscriptionPlanPrice::create(['plan' => SubscriptionPlan::Base, 'daily_price' => '1.00']);

        $this->actingAs($admin)->post(route('admin.subscriptions.store'), ['user_id' => $pharmacy->id, 'plan' => 'base', 'term' => $term])->assertRedirect();

        $this->assertSame($endsOn, Subscription::firstOrFail()->ends_on->toDateString());
    }

    /** @return array<string, array{string, string}> */
    public static function presetTerms(): array
    {
        return ['year' => ['year', '2027-08-31'], 'six months' => ['six_months', '2027-02-28'], 'three months' => ['three_months', '2026-11-30']];
    }

    public function test_grant_uses_inclusive_custom_end_date_and_supersedes_active_subscription(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00', 'Asia/Dushanbe'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $pharmacy = User::factory()->pharmacy()->create();
        SubscriptionPlanPrice::create(['plan' => SubscriptionPlan::Base, 'daily_price' => '1.00']);
        SubscriptionPlanPrice::create(['plan' => SubscriptionPlan::Premium, 'daily_price' => '3.00']);

        $this->actingAs($admin)->post(route('admin.subscriptions.store'), ['user_id' => $pharmacy->id, 'plan' => 'base', 'term' => 'custom', 'ends_on' => '2026-09-03'])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.subscriptions.store'), ['user_id' => $pharmacy->id, 'plan' => 'premium', 'term' => 'year'])->assertRedirect();

        $this->assertDatabaseHas('subscriptions', ['plan' => 'base', 'status' => 'superseded', 'total_price' => '3.00']);
        $premium = Subscription::query()->where('plan', 'premium')->firstOrFail();
        $this->assertSame(SubscriptionStatus::Active, $premium->status);
        $this->assertSame('2027-08-31', $premium->ends_on->toDateString());
        $this->assertSame(SubscriptionPlan::Premium, $pharmacy->refresh()->subscription_plan);
    }

    public function test_grant_rejects_free_non_pharmacy_unpriced_and_past_custom_subscriptions(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $wholesaler = User::factory()->wholesaler()->create();
        $pharmacy = User::factory()->pharmacy()->create();

        $this->actingAs($admin)->post(route('admin.subscriptions.store'), ['user_id' => $pharmacy->id, 'plan' => 'free', 'term' => 'year'])->assertSessionHasErrors('plan');
        $this->actingAs($admin)->post(route('admin.subscriptions.store'), ['user_id' => $wholesaler->id, 'plan' => 'base', 'term' => 'year'])->assertSessionHasErrors('user_id');
        $this->actingAs($admin)->post(route('admin.subscriptions.store'), ['user_id' => $pharmacy->id, 'plan' => 'base', 'term' => 'year'])->assertSessionHasErrors('plan');
        $this->actingAs($admin)->post(route('admin.subscriptions.store'), ['user_id' => $pharmacy->id, 'plan' => 'base', 'term' => 'custom', 'ends_on' => '2000-01-01'])->assertSessionHasErrors('ends_on');

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_expiry_command_marks_elapsed_subscription_expired_and_restores_free(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-12 00:01:00', 'Asia/Dushanbe'));
        $pharmacy = User::factory()->pharmacy()->create(['subscription_plan' => SubscriptionPlan::Base]);
        $subscription = Subscription::factory()->for($pharmacy)->create(['plan' => SubscriptionPlan::Base, 'ends_on' => '2026-09-11', 'status' => SubscriptionStatus::Active]);

        $this->artisan('subscriptions:expire')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::Expired, $subscription->refresh()->status);
        $this->assertNotNull($subscription->actual_ended_at);
        $this->assertSame(SubscriptionPlan::Free, $pharmacy->refresh()->subscription_plan);
        $this->assertDatabaseHas('audit_events', ['event' => 'subscription.expired', 'subject_id' => $subscription->id]);
    }
}
