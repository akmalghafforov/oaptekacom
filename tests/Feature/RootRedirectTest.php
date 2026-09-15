<?php

namespace Tests\Feature;

use App\Enums\TradeMode;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class RootRedirectTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_root_redirects_to_catalog(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/catalog');
    }

    public function test_root_redirects_authenticated_buyers_to_catalog(): void
    {
        $pharmacy = User::factory()->pharmacy()->create();
        $buyer = User::factory()->wholesaler()->create(['active_trade_mode' => TradeMode::Buyer]);

        $this->actingAs($pharmacy)->get('/')->assertRedirectToRoute('catalog');
        $this->actingAs($buyer)->get('/')->assertRedirectToRoute('catalog');
    }

    public function test_root_redirects_supplier_mode_and_admin_users_to_dashboard(): void
    {
        $supplier = User::factory()->wholesaler()->create(['active_trade_mode' => TradeMode::Supplier]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($supplier)->get('/')->assertRedirectToRoute('dashboard');
        $this->actingAs($admin)->get('/')->assertRedirectToRoute('dashboard');
    }
}
