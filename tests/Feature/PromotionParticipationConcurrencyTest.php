<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\PromotionItem;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class PromotionParticipationConcurrencyTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_simultaneous_confirmations_register_once_with_current_totals(bool $sameOrder): void
    {
        if (getenv('CART_CONCURRENCY_TESTS') !== '1' || ! function_exists('pcntl_fork') || ! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Opt in with CART_CONCURRENCY_TESTS=1 against a migrated, isolated MySQL/PostgreSQL test database.');
        }
        $this->assertMatchesRegularExpression('/(?:^|[_-])test(?:ing)?(?:[_-]|$)/i', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        config(['orders.placement_enabled' => true]);
        $buyer = User::factory()->pharmacy()->create();
        Subscription::factory()->for($buyer)->create();
        $supplier = User::factory()->wholesaler()->create();
        $promotion = Promotion::factory()->for($supplier->organization)->withParticipation()->published()->create();
        $item = PromotionItem::factory()->for($promotion)->create(['price' => '100.00']);
        $orders = [];
        for ($index = 0; $index < ($sameOrder ? 1 : 2); $index++) {
            $this->actingAs($buyer)->post(route('cart.promotions.add', $promotion), ['items' => [['id' => $item->id, 'quantity' => 1]]])->assertRedirect();
            $this->post(route('cart.checkout'))->assertRedirect(route('orders.index'));
            $orders[] = Order::latest('id')->firstOrFail();
        }
        $directory = sys_get_temp_dir().'/participation-concurrency-'.Str::uuid();
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();

        try {
            for ($index = 0; $index < 2; $index++) {
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
                        $this->actingAs($supplier->fresh())->patchJson(route('orders.status', $orders[$sameOrder ? 0 : $index]), ['status' => 'confirmed'])->assertRedirect();
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
            $participant = $promotion->participants()->sole();
            $this->assertTrue($participant->is_eligible);
            $this->assertSame($sameOrder ? 1 : 2, $participant->qualifying_order_count);
            $this->assertSame($sameOrder ? '100.00' : '200.00', $participant->qualifying_total);
            $this->assertSame($sameOrder ? 1 : 2, $promotion->contributions()->count());
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            DB::purge();
            $promotion->participants()->delete();
            $promotion->contributions()->delete();
            Order::whereIn('id', array_map(fn (Order $order): int => $order->id, $orders))->delete();
            DB::table('checkouts')->where('user_id', $buyer->id)->delete();
            Cart::where('user_id', $buyer->id)->delete();
            $item->forceDelete();
            $promotion->prizes()->delete();
            $promotion->delete();
            AuditEvent::whereIn('user_id', [$buyer->id, $supplier->id])->delete();
            $pharmacy = $buyer->organization;
            $supplierOrganization = $supplier->organization;
            $linkedPharmacy = $supplierOrganization->linkedPharmacyOrganization;
            $buyer->delete();
            $supplier->delete();
            $pharmacy->delete();
            $supplierOrganization->delete();
            $linkedPharmacy?->delete();
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
