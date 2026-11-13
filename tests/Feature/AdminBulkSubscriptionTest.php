<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTerm;
use App\Models\AuditEvent;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminBulkSubscriptionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_monitoring_has_inline_selection_and_a_separate_bulk_dialog(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $account = User::factory()->pharmacy()->create(['name' => '<script>Account</script>']);
        Subscription::factory()->count(2)->for($account)->create();
        $withoutHistory = User::factory()->pharmacy()->create();

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.index'));

        $response->assertSee($account->name)->assertDontSee($account->name, false)
            ->assertDontSee($withoutHistory->name)->assertSee('Массовые действия')
            ->assertSee('Выбрать все аккаунты на этой странице')->assertSee('3 месяца')
            ->assertSeeHtml('data-bulk-open')->assertSeeHtml('data-bulk-apply')
            ->assertSeeHtml('id="bulk-subscriptions-dialog"')->assertSeeHtml('data-bulk-form')
            ->assertSeeHtml('href="'.route('admin.subscriptions.create').'"')
            ->assertDontSee('data-account-search', false)->assertDontSee('Массовое управление');
        $this->assertSame(1, substr_count($response->getContent(), 'name="user_ids[]"'));
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//input[@data-account-checkbox]')->length);
        $this->assertSame(0, $xpath->query('//form//form')->length);
        $this->assertSame(1, $xpath->query('//button[@data-bulk-open and @disabled]')->length);
        $this->get('/admin/subscriptions/bulk')->assertStatus(405);
        $this->assertFalse(Route::has('admin.subscriptions.bulk.create'));
    }

    public function test_selection_controls_only_include_accounts_on_the_current_page(): void
    {
        $admin = $this->adminWithPrice();
        $older = Subscription::factory()->create();
        $account = User::factory()->pharmacy()->create();
        Subscription::factory()->count(30)->for($account)->create();

        $firstPage = $this->actingAs($admin)->get(route('admin.subscriptions.index'));
        $firstPage->assertSeeHtml('name="user_ids[]" value="'.$account->id.'"')
            ->assertDontSeeHtml('name="user_ids[]" value="'.$older->user_id.'"');
        $secondPage = $this->get(route('admin.subscriptions.index', ['page' => 2]));
        $secondPage->assertSeeHtml('name="user_ids[]" value="'.$older->user_id.'"')
            ->assertDontSeeHtml('name="user_ids[]" value="'.$account->id.'"');
    }

    public function test_set_replaces_active_coverage_and_assigns_expired_and_new_accounts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = $this->adminWithPrice();
        $active = Subscription::factory()->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', 'daily_price' => '1.50', 'total_price' => '183.00']);
        $expired = Subscription::factory()->create(['status' => SubscriptionStatus::Expired, 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31']);
        $newAccount = User::factory()->pharmacy()->create();
        $payment = PaymentRequest::create(['organization_id' => $active->user->organization_id, 'user_id' => $active->user_id, 'days' => 30, 'amount' => '30.00', 'status' => 'pending']);
        $paymentBefore = $payment->fresh()->getRawOriginal();
        $pending = Subscription::factory()->create(['payment_request_id' => $payment->id, 'user_id' => $active->user_id, 'status' => SubscriptionStatus::Pending, 'starts_on' => null, 'ends_on' => null]);
        $pendingBefore = $pending->fresh()->getRawOriginal();

        $this->actingAs($admin)->post(route('admin.subscriptions.bulk.store'), [
            'user_ids' => [$newAccount->id, $expired->user_id, $active->user_id], 'action' => 'set', 'plan' => 'premium', 'term' => 'three_months',
        ])->assertRedirect(route('admin.subscriptions.index'))->assertSessionHas('success', 'Подписки обновлены. Аккаунтов: 3.');

        foreach ([$active->user_id, $expired->user_id, $newAccount->id] as $id) {
            $this->assertDatabaseHas('subscriptions', ['user_id' => $id, 'assigned_by' => $admin->id, 'plan' => 'premium', 'term' => 'three_months', 'status' => 'active', 'starts_on' => '2026-09-12 00:00:00', 'ends_on' => '2026-12-11 00:00:00', 'daily_price' => '2.00', 'total_price' => '182.00']);
            $this->assertSame(SubscriptionPlan::Premium, User::findOrFail($id)->subscription_plan);
        }
        $this->assertSame(SubscriptionStatus::Superseded, $active->fresh()->status);
        $this->assertSame('2026-12-31', $active->fresh()->ends_on->toDateString());
        $this->assertSame('183.00', $active->fresh()->total_price);
        $this->assertNotNull($active->fresh()->actual_ended_at);
        $this->assertSame(SubscriptionStatus::Expired, $expired->fresh()->status);
        $this->assertSame($pendingBefore, $pending->fresh()->getRawOriginal());
        $this->assertSame($paymentBefore, $payment->fresh()->getRawOriginal());
        $this->assertSame('1.50', $active->fresh()->daily_price);
        $this->assertSame('2026-09-01', $active->fresh()->starts_on->toDateString());
        $this->assertSame(3, AuditEvent::where('event', 'subscription.bulk_set')->count());
        $audit = AuditEvent::where('event', 'subscription.bulk_set')->whereJsonContains('after->user_id', $active->user_id)->firstOrFail();
        $this->assertSame('2026-12-31', $audit->before['current_ends_on']);
        $this->assertSame('183.00', $audit->before['subscriptions'][0]['total_price']);
        $this->assertSame('2026-12-11', $audit->after['ends_on']);
        $this->assertSame('set', $audit->after['action']);
    }

    public function test_extend_preserves_different_remaining_terms_and_starts_overdue_accounts_today(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 00:15:00', 'Asia/Dushanbe'));
        $admin = $this->adminWithPrice();
        $today = Subscription::factory()->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-12']);
        $future = Subscription::factory()->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-20']);
        $overdue = Subscription::factory()->create(['starts_on' => '2026-08-01', 'ends_on' => '2026-09-11']);
        $newAccount = User::factory()->pharmacy()->create();

        $this->actingAs($admin)->post(route('admin.subscriptions.bulk.store'), [
            'user_ids' => [$today->user_id, $future->user_id, $overdue->user_id, $newAccount->id], 'action' => 'extend', 'plan' => 'premium', 'term' => 'ten_days',
        ])->assertRedirect(route('admin.subscriptions.index'));

        foreach ([[$today->user_id, '2026-09-22', '22.00'], [$future->user_id, '2026-09-30', '38.00'], [$overdue->user_id, '2026-09-21', '20.00'], [$newAccount->id, '2026-09-21', '20.00']] as [$id, $end, $price]) {
            $replacement = User::findOrFail($id)->subscriptions()->where('status', SubscriptionStatus::Active)->sole();
            $this->assertSame('2026-09-12', $replacement->starts_on->toDateString());
            $this->assertSame($end, $replacement->ends_on->toDateString());
            $this->assertSame($price, $replacement->total_price);
            $this->assertSame(SubscriptionPlan::Premium, $replacement->plan);
        }
        $this->assertSame(SubscriptionStatus::Superseded, $overdue->fresh()->status);
        $this->assertSame(4, AuditEvent::where('event', 'subscription.bulk_extend')->count());
    }

    #[TestWith(['2028-01-31', 'set', null, 'month', '2028-02-28', '58.00'])]
    #[TestWith(['2028-02-29', 'set', null, 'year', '2029-02-27', '730.00'])]
    #[TestWith(['2028-01-15', 'extend', '2028-01-30', 'three_months', '2028-04-29', '212.00'])]
    #[TestWith(['2028-01-31', 'set', null, 'six_months', '2028-07-30', '364.00'])]
    #[TestWith(['2028-01-31', 'set', null, 'custom', '2028-01-31', '2.00'])]
    #[TestWith(['2028-01-15', 'extend', '2028-01-30', 'custom', '2028-02-10', '54.00'])]
    public function test_calendar_terms_and_inclusive_prices(string $today, string $action, ?string $currentEnd, string $term, string $expectedEnd, string $expectedPrice): void
    {
        $this->travelTo(CarbonImmutable::parse($today.' 12:00:00', 'Asia/Dushanbe'));
        $admin = $this->adminWithPrice();
        $account = User::factory()->pharmacy()->create();
        if ($currentEnd !== null) {
            Subscription::factory()->create(['user_id' => $account->id, 'starts_on' => $today, 'ends_on' => $currentEnd]);
        }
        $this->actingAs($admin)->post(route('admin.subscriptions.bulk.store'), [
            'user_ids' => [$account->id], 'action' => $action, 'plan' => 'premium', 'term' => $term, 'ends_on' => $expectedEnd,
        ])->assertRedirect(route('admin.subscriptions.index'));
        $subscription = $account->subscriptions()->where('status', SubscriptionStatus::Active)->sole();
        $this->assertSame($expectedEnd, $subscription->ends_on->toDateString());
        $this->assertSame($expectedPrice, $subscription->total_price);
    }

    public function test_custom_date_failure_rolls_back_prior_accounts_history_plan_and_audits(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = $this->adminWithPrice();
        $first = User::factory()->pharmacy()->create();
        $firstHistory = Subscription::factory()->create(['user_id' => $first->id, 'ends_on' => '2026-09-15']);
        $lastHistory = Subscription::factory()->create(['ends_on' => '2026-09-30']);
        $this->actingAs($admin)->from(route('admin.subscriptions.index'))->post(route('admin.subscriptions.bulk.store'), [
            'user_ids' => [$lastHistory->user_id, $first->id], 'action' => 'extend', 'plan' => 'premium', 'term' => 'custom', 'ends_on' => '2026-09-30',
        ])->assertRedirect(route('admin.subscriptions.index'))->assertSessionHasErrors('ends_on');

        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertSame(SubscriptionStatus::Active, $firstHistory->fresh()->status);
        $this->assertNull($firstHistory->fresh()->actual_ended_at);
        $this->assertSame(SubscriptionStatus::Active, $lastHistory->fresh()->status);
        $this->assertSame(SubscriptionPlan::Free, $first->fresh()->subscription_plan);
        $this->assertSame(0, AuditEvent::where('event', 'like', 'subscription.bulk_%')->count());
    }

    #[TestWith([['user_ids' => []], 'user_ids'])]
    #[TestWith([['user_ids' => [999999]], 'user_ids.0'])]
    #[TestWith([['action' => 'delete'], 'action'])]
    #[TestWith([['plan' => 'free'], 'plan'])]
    #[TestWith([['plan' => 'invalid'], 'plan'])]
    #[TestWith([['term' => 'invalid'], 'term'])]
    #[TestWith([['term' => 'custom'], 'ends_on'])]
    #[TestWith([['term' => 'custom', 'ends_on' => 'yesterday'], 'ends_on'])]
    #[TestWith([['term' => 'custom', 'ends_on' => '2026-09-11'], 'ends_on'])]
    public function test_invalid_payloads_do_not_write_subscriptions(array $overrides, string $error): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = $this->adminWithPrice();
        $account = User::factory()->pharmacy()->create();
        $this->actingAs($admin)->post(route('admin.subscriptions.bulk.store'), array_replace([
            'user_ids' => [$account->id], 'action' => 'set', 'plan' => 'premium', 'term' => 'month',
        ], $overrides))->assertSessionHasErrors($error);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_duplicate_and_non_pharmacy_selections_are_rejected(): void
    {
        $admin = $this->adminWithPrice();
        $pharmacy = User::factory()->pharmacy()->create();
        $supplier = User::factory()->wholesaler()->create();
        $this->actingAs($admin)->post(route('admin.subscriptions.bulk.store'), [
            'user_ids' => [$pharmacy->id, (string) $pharmacy->id, $supplier->id], 'action' => 'set', 'plan' => 'premium', 'term' => 'month',
        ])->assertSessionHasErrors(['user_ids.0', 'user_ids.1', 'user_ids.2']);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    #[TestWith([null])]
    #[TestWith(['0.00'])]
    public function test_unavailable_pricing_preserves_all_accounts(?string $price): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $history = Subscription::factory()->create();
        if ($price !== null) {
            SubscriptionPlanPrice::factory()->create(['plan' => SubscriptionPlan::Premium, 'daily_price' => $price]);
        }
        $this->actingAs($admin)->post(route('admin.subscriptions.bulk.store'), [
            'user_ids' => [$history->user_id], 'action' => 'extend', 'plan' => 'premium', 'term' => 'month',
        ])->assertSessionHasErrors('bulk');
        $this->assertSame(SubscriptionStatus::Active, $history->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertSame(0, AuditEvent::where('event', 'like', 'subscription.bulk_%')->count());
    }

    public function test_validation_restores_selections_and_dialog_values_at_the_same_monitoring_url(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = $this->adminWithPrice();
        $account = User::factory()->pharmacy()->create(['phone' => '+992901234567']);
        Subscription::factory()->count(2)->for($account)->create();
        $page = route('admin.subscriptions.index', ['phone' => '90123', 'sort' => 'ends_on', 'direction' => 'asc', 'page' => 1]);
        $this->actingAs($admin)->from($page)->post(route('admin.subscriptions.bulk.store'), [
            'user_ids' => [$account->id], 'action' => 'extend', 'plan' => 'premium', 'term' => 'custom', 'ends_on' => '2026-09-11',
        ])->assertRedirect($page);
        $this->withCookie(config('session.cookie'), session()->getId())->get($page)
            ->assertSeeHtml('value="'.$account->id.'" checked')->assertSeeHtml('value="extend" selected')
            ->assertSeeHtml('value="premium" selected')->assertSeeHtml('value="custom" selected')
            ->assertSeeHtml('value="2026-09-11"')->assertSeeHtml('data-dialog-auto-open')
            ->assertSeeHtml('aria-describedby="ends_on-hint ends_on-error"')->assertSeeHtml('aria-invalid="true"');
    }

    public function test_empty_selection_reopens_the_dialog_with_errors(): void
    {
        $admin = $this->adminWithPrice();
        $page = route('admin.subscriptions.index');

        $this->actingAs($admin)->from($page)->post(route('admin.subscriptions.bulk.store'), [
            '_bulk_subscriptions' => '1', 'plan' => 'premium', 'term' => 'month',
        ])->assertRedirect($page);

        $this->withCookie(config('session.cookie'), session()->getId())->get($page)
            ->assertSeeHtml('data-dialog-auto-open')->assertSee('аккаунты аптек')
            ->assertSeeHtml('aria-invalid="true"');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_guest_and_business_roles_cannot_access_bulk_actions(): void
    {
        $this->get(route('admin.subscriptions.index'))->assertRedirect(route('login'));
        $this->post(route('admin.subscriptions.bulk.store'))->assertRedirect(route('login'));
        foreach ([User::factory()->pharmacy()->create(), User::factory()->wholesaler()->create()] as $user) {
            $this->actingAs($user)->get(route('admin.subscriptions.index'))->assertForbidden();
            $this->post(route('admin.subscriptions.bulk.store'))->assertForbidden();
        }
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_stale_mode_forms_and_admin_in_pharmacy_mode_are_rejected(): void
    {
        config(['account-modes.enabled' => true]);
        $admin = $this->adminWithPrice();
        $account = User::factory()->pharmacy()->create();
        $this->actingAs($admin)->get(route('admin.subscriptions.index'))->assertOk()->assertSee('name="_mode_version"', false);
        $version = session('account_mode.version');
        $this->post(route('admin.subscriptions.bulk.store'), [
            'user_ids' => [$account->id], 'action' => 'set', 'plan' => 'premium', 'term' => 'month', '_mode_version' => 'obsolete',
        ])->assertStatus(409);
        $this->patch(route('mode.update'), ['mode' => 'pharmacy', '_mode_version' => $version])->assertRedirect();
        $this->get(route('admin.subscriptions.index'))->assertForbidden();
        $this->post(route('admin.subscriptions.bulk.store'), ['_mode_version' => session('account_mode.version')])->assertForbidden();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_service_rechecks_changed_account_eligibility_and_missing_accounts(): void
    {
        $admin = $this->adminWithPrice();
        $account = User::factory()->pharmacy()->create();
        $supplier = User::factory()->wholesaler()->create();
        $account->forceFill(['role' => 'wholesaler', 'organization_id' => $supplier->organization_id])->save();
        try {
            app(SubscriptionService::class)->grantBulk([$account->id], $admin, 'set', SubscriptionPlan::Premium, SubscriptionTerm::Month);
            $this->fail('Changed account must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('subscriptions', 0);
        }
        $this->expectException(InvalidArgumentException::class);
        app(SubscriptionService::class)->grantBulk([999999], $admin, 'set', SubscriptionPlan::Premium, SubscriptionTerm::Month);
    }

    #[TestWith(['expired', '2026-08-01', '2026-08-31'])]
    #[TestWith(['cancelled', '2026-09-01', '2026-12-31'])]
    #[TestWith(['active', '2026-10-01', '2026-12-31'])]
    public function test_extend_ignores_records_without_current_coverage(string $status, string $start, string $end): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = $this->adminWithPrice();
        $history = Subscription::factory()->create(['status' => $status, 'starts_on' => $start, 'ends_on' => $end]);

        $this->actingAs($admin)->post(route('admin.subscriptions.bulk.store'), [
            'user_ids' => [$history->user_id], 'action' => 'extend', 'plan' => 'premium', 'term' => 'ten_days',
        ])->assertRedirect(route('admin.subscriptions.index'));

        $replacement = $history->user->subscriptions()->where('status', SubscriptionStatus::Active)->sole();
        $this->assertSame('2026-09-21', $replacement->ends_on->toDateString());
        $this->assertSame('20.00', $replacement->total_price);
        $this->assertSame($end, $history->fresh()->ends_on->toDateString());
    }

    public function test_bulk_assignment_preserves_account_and_organization_access_guards(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = $this->adminWithPrice();
        $blocked = User::factory()->pharmacy()->create(['is_blocked' => true]);
        $pending = User::factory()->pharmacy()->create();
        $pending->organization->update(['status' => 'pending']);

        $this->actingAs($admin)->post(route('admin.subscriptions.bulk.store'), [
            'user_ids' => [$blocked->id, $pending->id], 'action' => 'set', 'plan' => 'premium', 'term' => 'month',
        ])->assertRedirect(route('admin.subscriptions.index'));

        $this->assertFalse($blocked->fresh()->hasActiveSubscription());
        $this->assertFalse($pending->fresh()->hasActiveSubscription());
        $this->assertDatabaseCount('subscriptions', 2);
    }

    private function adminWithPrice(): User
    {
        SubscriptionPlanPrice::factory()->create(['plan' => SubscriptionPlan::Premium, 'daily_price' => '2.00']);

        return User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
    }
}
