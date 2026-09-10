<?php

namespace Tests\Feature;

use App\Enums\TradeMode;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_wholesaler_in_buyer_mode_cannot_change_supplier_order_status(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $buyer = Organization::factory()->pharmacy()->create();
        $user = User::factory()->wholesaler($supplier)->create(['active_trade_mode' => TradeMode::Buyer]);
        $order = Order::create(['buyer_organization_id' => $buyer->id, 'supplier_organization_id' => $supplier->id]);

        $this->actingAs($user)->patch(route('orders.status', $order), ['status' => 'confirmed'])->assertForbidden();
    }

    public function test_user_cannot_view_a_cart_owned_by_another_user(): void
    {
        $organization = Organization::factory()->pharmacy()->create();
        $owner = User::factory()->pharmacy($organization)->create();
        $other = User::factory()->pharmacy($organization)->create();
        $cart = Cart::create(['user_id' => $owner->id]);

        $this->assertFalse($other->can('view', $cart));
    }

    public function test_pending_pharmacy_is_redirected_to_subscription_page(): void
    {
        $organization = Organization::factory()->pending()->create();
        $user = User::factory()->pharmacy($organization)->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('subscription.create'));
    }
}
