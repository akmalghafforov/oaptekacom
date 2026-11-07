<?php

namespace Tests\Feature;

use App\Enums\PromotionStatus;
use App\Models\AuditEvent;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionItem;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PromotionWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PromotionConcurrencyTest extends TestCase
{
    #[TestWith(['price'])]
    #[TestWith(['dates'])]
    #[TestWith(['status'])]
    #[TestWith(['stock'])]
    public function test_promotion_edits_and_checkout_serialize_without_sending_unconfirmed_conditions(string $change): void
    {
        if (getenv('CART_CONCURRENCY_TESTS') !== '1' || ! function_exists('pcntl_fork') || ! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Opt in with CART_CONCURRENCY_TESTS=1 against a migrated, isolated MySQL/PostgreSQL test database.');
        }
        $this->assertMatchesRegularExpression('/(?:^|[_-])test(?:ing)?(?:[_-]|$)/i', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        config(['orders.placement_enabled' => true]);
        $buyer = User::factory()->pharmacy()->create();
        Subscription::factory()->for($buyer)->create();
        $admin = User::factory()->admin()->create();
        $adminOrganizations = $admin->roleAssignments()->with('organization')->get()->pluck('organization')->filter();
        $promotion = Promotion::factory()->published()->create();
        $supplier = $promotion->organization;
        $item = PromotionItem::factory()->for($promotion)->create(['price' => '10.00']);
        $this->actingAs($buyer)->post(route('cart.promotions.add', $promotion), ['items' => [['id' => $item->id, 'quantity' => 3]]])->assertRedirect();
        $cart = Cart::where('user_id', $buyer->id)->sole();
        $row = $cart->items()->sole();
        $originalSnapshot = $row->snapshot;
        $directory = sys_get_temp_dir().'/promotion-concurrency-'.Str::uuid();
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();

        try {
            foreach (['edit', 'checkout'] as $index => $operation) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork test worker');
                }
                if ($pid === 0) {
                    try {
                        DB::purge();
                        touch($directory.'/ready-'.$index);
                        $deadline = microtime(true) + 10;
                        while (! is_file($directory.'/go')) {
                            if (microtime(true) > $deadline) {
                                throw new RuntimeException('Barrier expired');
                            }
                            usleep(10000);
                        }
                        if ($operation === 'edit') {
                            app(PromotionWorkflow::class)->locked($promotion, function (Promotion $current) use ($change, $admin, $item): void {
                                if ($change === 'price' || $change === 'stock') {
                                    $currentItem = $current->items()->lockForUpdate()->findOrFail($item->id);
                                    $currentItem->fill($change === 'price' ? ['price' => '20.00'] : ['stock' => 0]);
                                    $currentItem->terms_version++;
                                    $currentItem->save();
                                } elseif ($change === 'dates') {
                                    $current->ends_on = $current->ends_on->addDay();
                                } else {
                                    $current->status = PromotionStatus::Draft;
                                }
                                app(PromotionWorkflow::class)->edited($current, $admin);
                            });
                        } else {
                            $this->actingAs($buyer->fresh());
                            $response = $this->postJson(route('cart.checkout'));
                            if (! in_array($response->status(), [302, 422], true)) {
                                throw new RuntimeException('Checkout returned '.$response->status());
                            }
                        }
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($directory.'/error-'.$index, $exception->getMessage());
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-*')) !== 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            touch($directory.'/go');
            foreach ($children as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status), is_file($directory.'/error-'.$index) ? file_get_contents($directory.'/error-'.$index) : 'Worker failed');
            }
            DB::purge();
            $order = Order::where('buyer_organization_id', $buyer->organization_id)->first();
            if ($order !== null) {
                $this->assertSame('30.00', $order->total);
                $this->assertSame($originalSnapshot, $order->items()->sole()->snapshot);
                $this->assertSame($item->id, $order->items()->sole()->promotion_item_id);
                $this->assertModelMissing($row);
            } else {
                $this->assertSame(3, $row->fresh()->quantity);
                $this->assertSame($originalSnapshot, $row->fresh()->snapshot);
                $this->assertSame('10.00', $row->fresh()->unit_price);
            }
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            DB::purge();
            Order::where('buyer_organization_id', $buyer->organization_id)->delete();
            DB::table('checkouts')->where('user_id', $buyer->id)->delete();
            $cart->delete();
            foreach ([Cart::class => $cart->id, CartItem::class => $row->id, Promotion::class => $promotion->id] as $type => $id) {
                AuditEvent::where('subject_type', $type)->where('subject_id', $id)->delete();
            }
            $item->forceDelete();
            $promotion->delete();
            $supplier->delete();
            $buyerOrganization = $buyer->organization;
            $buyer->delete();
            $buyerOrganization->delete();
            $admin->delete();
            foreach ($adminOrganizations->sortByDesc('linked_pharmacy_organization_id') as $organization) {
                $organization->delete();
            }
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
