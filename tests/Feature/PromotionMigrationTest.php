<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Offer;
use App\Models\Order;
use App\Models\SupplierRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromotionMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_upgrade_preserves_existing_ordinary_basket_and_historical_order_and_archive(): void
    {
        $user = User::factory()->pharmacy()->create();
        $offer = Offer::factory()->create();
        $snapshot = ['medicine' => 'Исторический товар', 'supplier' => $offer->organization->name, 'price' => '30.00'];
        $cart = Cart::create(['user_id' => $user->id]);
        $cart->items()->create(['offer_id' => $offer->id, 'quantity' => 4, 'unit_price' => '30.00', 'snapshot' => $snapshot]);
        $order = Order::create(['buyer_organization_id' => $user->organization_id, 'supplier_organization_id' => $offer->organization_id, 'total' => '120.00']);
        $order->items()->create(['offer_id' => $offer->id, 'medicine_id' => $offer->medicine_id, 'quantity' => 4, 'unit_price' => '30.00', 'snapshot' => $snapshot]);
        $archive = SupplierRequest::factory()->create(['user_id' => $user->id, 'buyer_organization_id' => $user->organization_id, 'supplier_organization_id' => $offer->organization_id]);
        $archive->items()->create(['offer_id' => $offer->id, 'medicine_id' => $offer->medicine_id, 'product_name' => 'Исторический товар', 'quantity' => 4, 'unit_price' => '30.00', 'line_total' => '120.00', 'snapshot' => $snapshot]);
        $migration = require database_path('migrations/2026_11_04_234903_create_promotions_system.php');
        $migration->down();
        $tables = ['cart_items', 'order_items', 'supplier_request_items', 'orders', 'supplier_requests'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        $migration->up();

        foreach ($tables as $table) {
            $after = DB::table($table)->orderBy('id')->get()->map(function ($row): array {
                $attributes = (array) $row;
                unset($attributes['promotion_id'], $attributes['promotion_item_id'], $attributes['accepted_terms_version']);

                return $attributes;
            })->all();
            $this->assertSame($before[$table], $after);
        }
        $otherCart = Cart::create(['user_id' => User::factory()->pharmacy()->create()->id]);
        $otherCart->items()->create(['offer_id' => $offer->id, 'quantity' => 1, 'unit_price' => '30.00', 'snapshot' => $snapshot]);
        $this->assertDatabaseCount('cart_items', 2);
    }
}
