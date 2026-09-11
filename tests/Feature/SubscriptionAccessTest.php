<?php

namespace Tests\Feature;

use App\Models\Organization;
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
}
