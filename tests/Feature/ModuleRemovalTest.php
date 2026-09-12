<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModuleRemovalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_former_admin_module_endpoints_return_404(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/modules')->assertNotFound();
        $this->actingAs($admin)->patch('/admin/modules/1', ['enabled' => false])->assertNotFound();
    }

    public function test_module_settings_table_is_absent(): void
    {
        $this->assertFalse(Schema::hasTable('module_settings'));
    }

    public function test_eligible_user_can_access_catalog_cart_and_orders_without_a_module_gate(): void
    {
        $user = User::factory()
            ->pharmacy(Organization::factory()->pharmacy()->create())
            ->create();
        Subscription::factory()->for($user)->create();

        $this->actingAs($user)->get(route('catalog'))->assertOk();
        $this->actingAs($user)->get(route('cart'))->assertOk();
        $this->actingAs($user)->get(route('orders.index'))->assertOk();
    }
}
