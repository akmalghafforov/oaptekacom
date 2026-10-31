<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Models\AuditEvent;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\Subscription;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\PriceList\ImportActivator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class CartConcurrencyTest extends TestCase
{
    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    #[TestWith(['checkout'])]
    #[TestWith(['agreement'])]
    #[TestWith(['update', 'agreement'])]
    #[TestWith(['delete', 'agreement'])]
    #[TestWith(['checkout', 'agreement'])]
    public function test_activation_and_customer_actions_serialize_without_losing_requested_items(string $action, string $concurrentOperation = 'activation'): void
    {
        if (getenv('CART_CONCURRENCY_TESTS') !== '1' || ! function_exists('pcntl_fork') || ! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Opt in with CART_CONCURRENCY_TESTS=1 against a migrated, isolated MySQL/PostgreSQL test database.');
        }
        $this->assertMatchesRegularExpression('/(?:^|[_-])test(?:ing)?(?:[_-]|$)/i', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        config()->set('orders.placement_enabled', true);
        $buyer = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($buyer)->create();
        Subscription::factory()->for($user)->create();
        $supplier = Organization::factory()->wholesaler()->create();
        $product = SupplierProduct::factory()->for($supplier, 'supplier')->create();
        $old = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed, 'inventory_at' => null]);
        $supplier->update(['active_price_list_import_id' => $old->id]);
        $offer = Offer::factory()->for($supplier, 'organization')->create(['medicine_id' => $product->medicine_id, 'supplier_product_id' => $product->id, 'price_list_import_id' => $old->id, 'quantity' => 100, 'price' => '10.00']);
        $cart = Cart::create(['user_id' => $user->id]);
        $item = $cart->items()->create(['offer_id' => $offer->id, 'quantity' => 7, 'unit_price' => '10.00', 'snapshot' => ['medicine' => 'Concurrent product', 'supplier' => $supplier->name, 'supplier_discount_percent' => null]]);
        $next = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Preview, 'inventory_at' => null]);
        $row = PriceListImportRow::create(['price_list_import_id' => $next->id, 'source_row' => 1, 'raw_values' => [], 'supplier_product_id' => $product->id, 'medicine_id' => $product->medicine_id,
            'parsed_values' => ['name' => $product->original_name, 'normalized_name' => $product->normalized_name, 'price' => '20.00', 'quantity' => 100, 'total' => null, 'expiration' => null],
            'disposition' => PriceListRowDisposition::Valid, 'planned_action' => PriceListRowAction::Update, 'errors' => [], 'warnings' => []]);
        app(ImportActivator::class)->materialize($next, $row->id, $row->id);
        $directory = sys_get_temp_dir().'/cart-concurrency-'.Str::uuid();
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect();

        try {
            foreach ([$concurrentOperation, $action] as $index => $operation) {
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
                        if ($operation === 'activation') {
                            app(ImportActivator::class)->commit($next->fresh());
                        } else {
                            $this->actingAs($user->fresh());
                            $response = match ($operation) {
                                'agreement' => $this->patch(route('partners.discount', $supplier), ['supplier_discount_percent' => '5.00']),
                                'update' => $this->patchJson(route('cart.update', $item), ['quantity' => 8]),
                                'delete' => $this->deleteJson(route('cart.items.destroy', $item)),
                                'checkout' => $this->postJson(route('cart.checkout')),
                            };
                            if (! in_array($response->status(), [200, 302, 422], true)) {
                                throw new RuntimeException('Customer action returned '.$response->status());
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
            $this->assertSame($concurrentOperation === 'activation' ? $next->id : $old->id, $supplier->fresh()->active_price_list_import_id);
            if ($action === 'agreement') {
                $this->assertSame('5.00', $item->fresh()->snapshot['supplier_discount_percent']);
                $this->assertSame('19.00', $item->fresh()->proposed_unit_price);
                $this->assertContains($item->fresh()->unit_price, ['9.50', '10.00']);
            } elseif ($action === 'update') {
                $this->assertSame(8, $item->fresh()->quantity);
                $this->assertSame($concurrentOperation === 'activation' ? $next->offers()->sole()->id : $offer->id, $item->fresh()->offer_id);
                if ($concurrentOperation === 'agreement') {
                    $this->assertSame('9.50', $item->fresh()->unit_price);
                }
            } elseif ($action === 'checkout' && $item->fresh() !== null) {
                $this->assertSame(7, $item->fresh()->quantity);
                $this->assertSame('10.00', $item->fresh()->unit_price);
                $this->assertSame('20.00', $item->fresh()->proposed_unit_price);
                $this->assertSame(0, Order::where('buyer_organization_id', $buyer->id)->count());
            } else {
                $this->assertModelMissing($item);
                $this->assertDatabaseHas('audit_events', ['subject_type' => Cart::class, 'subject_id' => $cart->id, 'event' => $action === 'delete' ? 'cart.item_deleted' : 'cart.checkout_removed']);
                if ($action === 'checkout') {
                    $this->assertSame(7, Order::where('buyer_organization_id', $buyer->id)->sole()->items()->sole()->quantity);
                }
            }
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
            DB::purge();
            AuditEvent::where('subject_type', Cart::class)->where('subject_id', $cart->id)->delete();
            AuditEvent::where('subject_type', CartItem::class)->where('subject_id', $item->id)->delete();
            AuditEvent::where('subject_type', PriceListImport::class)->whereIn('subject_id', [$old->id, $next->id])->delete();
            Order::where('buyer_organization_id', $buyer->id)->delete();
            DB::table('checkouts')->where('user_id', $user->id)->delete();
            $user->delete();
            $supplier->update(['active_price_list_import_id' => null]);
            $supplier->delete();
            $product->medicine->delete();
            $buyer->delete();
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
