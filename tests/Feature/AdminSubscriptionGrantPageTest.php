<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPlan;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminSubscriptionGrantPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_grant_page_requires_authentication_and_admin_access(): void
    {
        $this->get(route('admin.subscriptions.create'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->pharmacy()->create())
            ->get(route('admin.subscriptions.create'))->assertForbidden();
        $this->actingAs(User::factory()->wholesaler()->create())
            ->get(route('admin.subscriptions.create'))->assertForbidden();
    }

    public function test_admin_can_preselect_a_pharmacy_account_identified_by_name_and_phone(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $account = User::factory()->pharmacy()->create(['phone' => '+992901234567']);
        $supplier = User::factory()->wholesaler()->create();

        $response = $this->actingAs($admin)->get(route('admin.subscriptions.create', ['user_id' => $account->id]));

        $response->assertSeeHtml('value="'.$account->id.'" selected')
            ->assertSee($account->organization->name.' · '.$account->name.' · '.$account->phone)
            ->assertDontSee($supplier->name)
            ->assertSeeHtml('action="'.route('admin.subscriptions.store').'"');
        $this->get(route('admin.pharmacies.show', $account->organization))->assertSeeHtml('href="'.e(route('admin.subscriptions.create', ['user_id' => $account->id])).'"');
    }

    public function test_validation_recovers_submitted_values_on_the_grant_page(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $account = User::factory()->pharmacy()->create();
        $page = route('admin.subscriptions.create', ['user_id' => $account->id]);

        $response = $this->actingAs($admin)->from($page)->post(route('admin.subscriptions.store'), [
            'user_id' => $account->id, 'plan' => 'premium', 'term' => 'custom', 'ends_on' => '2026-09-11',
        ]);

        $response->assertRedirect($page)->assertSessionHasErrors('ends_on');
        $this->withCookie(config('session.cookie'), session()->getId())->get($page)->assertSeeHtml('value="'.$account->id.'" selected')
            ->assertSeeHtml('value="premium" selected')->assertSeeHtml('value="custom" selected')
            ->assertSeeHtml('value="2026-09-11"')->assertSeeHtml('aria-invalid="true"');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_successful_grant_returns_to_monitoring_with_feedback(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00:00', 'Asia/Dushanbe'));
        $admin = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $account = User::factory()->pharmacy()->create();
        SubscriptionPlanPrice::factory()->create(['plan' => SubscriptionPlan::Base, 'daily_price' => '2.00']);

        $response = $this->actingAs($admin)->from(route('admin.subscriptions.create'))->post(route('admin.subscriptions.store'), [
            'user_id' => $account->id, 'plan' => 'base', 'term' => 'custom', 'ends_on' => '2026-09-12',
        ]);

        $response->assertRedirect(route('admin.subscriptions.index'))
            ->assertSessionHas('success', 'Подписка выдана и сохранена в истории.');
        $this->assertDatabaseHas('subscriptions', ['user_id' => $account->id, 'status' => 'active', 'total_price' => '2.00']);
        $this->get(route('admin.subscriptions.index'))->assertSee('Подписка выдана и сохранена в истории.');
    }
}
