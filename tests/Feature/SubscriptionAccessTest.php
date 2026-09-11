<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SubscriptionAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_active_pharmacy_on_free_plan_can_access_the_platform(): void
    {
        $organization = Organization::factory()->pharmacy()->create(['subscription_until' => now()->subDay()]);
        $user = User::factory()->pharmacy($organization)->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_non_admin_cannot_manage_subscriptions(): void
    {
        $user = User::factory()->pharmacy()->create();

        $this->actingAs($user)->get(route('admin.subscriptions.index'))->assertForbidden();
    }
}
