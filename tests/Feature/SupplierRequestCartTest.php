<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Models\Cart;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\Subscription;
use App\Models\SupplierRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SupplierRequestCartTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_successful_supplier_share_archives_snapshots_and_keeps_other_supplier_cart_items(): void
    {
        [$user, $firstSupplier, $firstOffer, $secondOffer] = $this->cartWithTwoSuppliers();
        $this->actingAs($user)->post(route('cart.add', $firstOffer), ['quantity' => 2]);
        $this->actingAs($user)->post(route('cart.add', $secondOffer), ['quantity' => 1]);

        $this->actingAs($user)->post(route('cart.suppliers.share', $firstSupplier), ['shared_via' => 'clipboard'])
            ->assertRedirect(route('cart'));

        $supplierRequest = SupplierRequest::query()->sole();
        $this->assertSame($user->id, $supplierRequest->user_id);
        $this->assertSame($firstSupplier->name, $supplierRequest->supplier_name);
        $this->assertSame('20.00', $supplierRequest->total);
        $this->assertSame('clipboard', $supplierRequest->shared_via);
        $this->assertSame('Первый товар', $supplierRequest->items()->sole()->product_name);
        $this->assertSame('20.00', $supplierRequest->items()->sole()->line_total);

        $cart = Cart::query()->where('user_id', $user->id)->sole();
        $this->assertSame([$secondOffer->id], $cart->items()->pluck('offer_id')->all());
    }

    public function test_supplier_checkout_creates_one_order_and_preserves_other_supplier_items(): void
    {
        [$user, $firstSupplier, $firstOffer, $secondOffer] = $this->cartWithTwoSuppliers();
        $this->actingAs($user)->post(route('cart.add', $firstOffer), ['quantity' => 2]);
        $this->actingAs($user)->post(route('cart.add', $secondOffer), ['quantity' => 1]);

        $this->actingAs($user)->post(route('cart.suppliers.checkout', $firstSupplier))
            ->assertRedirect(route('orders.index'));

        $order = Order::query()->sole();
        $this->assertSame($firstSupplier->id, $order->supplier_organization_id);
        $this->assertSame('20.00', $order->total);
        $this->assertSame(2, $order->items()->sole()->quantity);

        $cart = Cart::query()->where('user_id', $user->id)->sole();
        $this->assertSame([$secondOffer->id], $cart->items()->pluck('offer_id')->all());
    }

    public function test_clearing_a_supplier_request_preserves_other_supplier_items(): void
    {
        [$user, $firstSupplier, $firstOffer, $secondOffer] = $this->cartWithTwoSuppliers();
        $this->actingAs($user)->post(route('cart.add', $firstOffer), ['quantity' => 1]);
        $this->actingAs($user)->post(route('cart.add', $secondOffer), ['quantity' => 1]);

        $this->actingAs($user)->delete(route('cart.suppliers.destroy', $firstSupplier))
            ->assertSessionHas('success');

        $cart = Cart::query()->where('user_id', $user->id)->sole();
        $this->assertSame([$secondOffer->id], $cart->items()->pluck('offer_id')->all());
    }

    public function test_quantity_update_rejects_a_value_above_the_supplier_stock(): void
    {
        [$user, , $offer] = $this->cartWithTwoSuppliers();
        $this->actingAs($user)->post(route('cart.add', $offer), ['quantity' => 1]);
        $cartItem = Cart::query()->where('user_id', $user->id)->sole()->items()->sole();

        $this->actingAs($user)->patch(route('cart.update', $cartItem), ['quantity' => 11])
            ->assertSessionHasErrors(['quantity' => 'Предложение устарело или нужное количество больше остатка.']);

        $this->assertSame(1, $cartItem->fresh()->quantity);
    }

    public function test_supplier_request_archive_is_private_to_its_creator(): void
    {
        [$user, $supplier, $offer] = $this->cartWithTwoSuppliers();
        $this->actingAs($user)->post(route('cart.add', $offer), ['quantity' => 1]);
        $this->actingAs($user)->post(route('cart.suppliers.share', $supplier), ['shared_via' => 'native']);
        $supplierRequest = SupplierRequest::query()->sole();
        $otherUser = $this->pharmacyUser();

        $this->actingAs($otherUser)->get(route('supplier-requests.show', $supplierRequest))->assertNotFound();
        $this->actingAs($otherUser)->get(route('supplier-requests.index'))->assertDontSee($supplier->name);
        $this->actingAs($user)->get(route('supplier-requests.show', $supplierRequest))->assertSee('Первый товар');
    }

    public function test_supplier_request_search_and_exports_use_the_archived_snapshot(): void
    {
        [$user, $supplier, $offer] = $this->cartWithTwoSuppliers();
        $this->actingAs($user)->post(route('cart.add', $offer), ['quantity' => 1]);
        $this->actingAs($user)->post(route('cart.suppliers.share', $supplier), ['shared_via' => 'clipboard']);
        $supplierRequest = SupplierRequest::query()->sole();
        $supplier->update(['name' => 'Новое имя поставщика']);
        $offer->medicine->update(['name' => 'Новое имя товара']);

        $this->actingAs($user)->get(route('supplier-requests.index', ['q' => 'Первый товар']))->assertSee('Первый товар');
        $this->actingAs($user)->get(route('supplier-requests.print', $supplierRequest))->assertSee($supplierRequest->supplier_name)->assertSee('Первый товар');
        $export = $this->actingAs($user)->get(route('supplier-requests.excel', $supplierRequest))->assertDownload();
        $file = tempnam(sys_get_temp_dir(), 'oapteka-request-');
        file_put_contents($file, $export->streamedContent());
        $spreadsheet = IOFactory::load($file);
        unlink($file);

        $this->assertSame('Первый товар', $spreadsheet->getActiveSheet()->getCell('A5')->getValue());
        $this->assertSame('Итого', $spreadsheet->getActiveSheet()->getCell('C6')->getValue());
    }

    /** @return array{User, Organization, Offer, Offer} */
    private function cartWithTwoSuppliers(): array
    {
        $user = $this->pharmacyUser();
        $firstSupplier = Organization::factory()->wholesaler()->create(['name' => 'Первый поставщик']);
        $secondSupplier = Organization::factory()->wholesaler()->create(['name' => 'Второй поставщик']);
        $firstImport = $this->activeImport($firstSupplier);
        $secondImport = $this->activeImport($secondSupplier);
        $firstOffer = $this->offer($firstSupplier, $firstImport, 'Первый товар', '10.00');
        $secondOffer = $this->offer($secondSupplier, $secondImport, 'Второй товар', '15.00');

        return [$user, $firstSupplier, $firstOffer, $secondOffer];
    }

    private function pharmacyUser(): User
    {
        $organization = Organization::factory()->pharmacy()->create();
        $user = User::factory()->pharmacy($organization)->create();
        Subscription::factory()->for($user)->create();

        return $user;
    }

    private function activeImport(Organization $supplier): PriceListImport
    {
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create(['status' => PriceListImportStatus::Completed]);
        $supplier->update(['active_price_list_import_id' => $import->id]);

        return $import;
    }

    private function offer(Organization $supplier, PriceListImport $import, string $name, string $price): Offer
    {
        $medicine = Medicine::factory()->create(['supplier_organization_id' => $supplier->id, 'name' => $name, 'normalized_name' => $name, 'search_text' => mb_strtolower($name)]);

        return Offer::factory()->for($supplier, 'organization')->for($medicine)->create(['price_list_import_id' => $import->id, 'price' => $price, 'quantity' => 10]);
    }
}
