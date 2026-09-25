<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\PharmacySupplierDiscount;
use App\Models\SupplierRequest;
use App\Services\SupplierDiscountPrice;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CartController extends Controller
{
    private function cart(Request $request): Cart
    {
        return Cart::firstOrCreate(['user_id' => $request->user()->id]);
    }

    public function show(Request $request): View
    {
        abort_unless($request->user()->canBuy(), 403);
        $cart = $this->cart($request);
        $removed = $cart->items()->whereDoesntHave('offer', fn ($query) => $query->currentAvailable())->delete();

        $cart->load('items.offer.medicine', 'items.offer.organization');
        $supplierGroups = $cart->items
            ->groupBy(fn (CartItem $item): int => $item->offer->organization_id)
            ->map(function ($items): array {
                $total = $items->reduce(
                    fn (string $total, CartItem $item): string => bcadd($total, bcmul((string) $item->unit_price, (string) $item->quantity, 2), 2),
                    '0.00',
                );

                return ['supplier' => $items->first()->offer->organization, 'items' => $items, 'total' => $total];
            });

        return view('orders.cart', ['cart' => $cart, 'supplierGroups' => $supplierGroups, 'removedStaleItems' => $removed]);
    }

    public function add(Offer $offer, Request $request, SupplierDiscountPrice $supplierDiscountPrice): RedirectResponse|JsonResponse
    {
        $this->authorize('view', $offer);
        abort_unless($request->user()->canBuy() && $this->isCurrent($offer), 404);
        $validated = $request->validate(['quantity' => 'nullable|integer|min:1|max:999']);
        $cartItem = CartItem::firstOrNew(['cart_id' => $this->cart($request)->id, 'offer_id' => $offer->id]);
        $cartItem->quantity = ($cartItem->exists ? $cartItem->quantity : 0) + ($validated['quantity'] ?? 1);
        if ($offer->quantity !== null && $cartItem->quantity > (float) $offer->quantity) {
            throw ValidationException::withMessages(['quantity' => 'Запрошенное количество превышает остаток поставщика.']);
        }
        if (! $cartItem->exists) {
            $supplierDiscountPercent = $request->user()->isCustomer()
                ? PharmacySupplierDiscount::query()
                    ->where('pharmacy_organization_id', $request->user()->organization_id)
                    ->where('supplier_organization_id', $offer->organization_id)
                    ->value('supplier_discount_percent')
                : null;
            $unitPrice = $supplierDiscountPrice->effectivePrice((string) $offer->price, $supplierDiscountPercent);

            $cartItem->unit_price = $unitPrice;
            $cartItem->snapshot = [
                'medicine' => $offer->medicine->name,
                'supplier' => $offer->organization->name,
                'price' => $offer->price,
                'raw_price' => $offer->price,
                'supplier_discount_percent' => $supplierDiscountPercent,
                'source_name' => $offer->source_name,
                'batch' => $offer->batch,
                'expires_at' => $offer->expires_at?->toDateString(),
            ];
        }
        $cartItem->save();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Товар добавлен в корзину.',
                'cart' => ['total_quantity' => (int) $cartItem->cart->items()->sum('quantity')],
                'item' => ['offer_id' => $offer->id, 'quantity' => $cartItem->quantity],
            ]);
        }

        return back()->with('success', 'Товар добавлен в корзину.');
    }

    public function update(CartItem $item, Request $request): RedirectResponse
    {
        $this->authorize('update', $item->cart);
        $validated = $request->validate(['quantity' => 'required|integer|min:1|max:999']);
        if (! $this->isCurrent($item->offer) || ($item->offer->quantity !== null && $validated['quantity'] > (float) $item->offer->quantity)) {
            throw ValidationException::withMessages(['quantity' => 'Предложение устарело или нужное количество больше остатка.']);
        }
        $item->update($validated);

        return back();
    }

    public function destroy(CartItem $item, Request $request): RedirectResponse
    {
        $this->authorize('update', $item->cart);
        $item->delete();

        return back()->with('success', 'Позиция удалена из корзины.');
    }

    public function destroySupplier(Organization $supplier, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canBuy(), 403);
        $cart = $this->cart($request);
        $deleted = $this->supplierItems($cart, $supplier)->delete();
        abort_if($deleted === 0, 404);

        return back()->with('success', 'Заявка поставщику очищена.');
    }

    public function checkoutSupplier(Organization $supplier, Request $request, SupplierDiscountPrice $supplierDiscountPrice): RedirectResponse
    {
        abort_unless($request->user()->canBuy(), 403);
        $cart = $this->cart($request);

        DB::transaction(function () use ($cart, $supplier, $request, $supplierDiscountPrice): void {
            $cartItems = $this->supplierItems($cart, $supplier)->with('offer')->get();
            abort_if($cartItems->isEmpty(), 404);
            $this->validateCurrentItems($cartItems);
            $total = $this->totalFor($cartItems, $supplierDiscountPrice);
            $checkoutId = DB::table('checkouts')->insertGetId(['buyer_organization_id' => $request->user()->organization_id, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $order = Order::create(['checkout_id' => $checkoutId, 'buyer_organization_id' => $request->user()->organization_id, 'supplier_organization_id' => $supplier->id, 'total' => $total]);

            foreach ($cartItems as $cartItem) {
                OrderItem::create(['order_id' => $order->id, 'offer_id' => $cartItem->offer_id, 'medicine_id' => $cartItem->offer->medicine_id, 'quantity' => $cartItem->quantity, 'unit_price' => $cartItem->unit_price, 'snapshot' => $cartItem->snapshot]);
            }

            CartItem::query()->whereKey($cartItems->modelKeys())->delete();
        });

        return redirect()->route('orders.index')->with('success', 'Заказ передан поставщику.');
    }

    public function shareSupplier(Organization $supplier, Request $request, SupplierDiscountPrice $supplierDiscountPrice): RedirectResponse
    {
        abort_unless($request->user()->canBuy(), 403);
        $validated = $request->validate(['shared_via' => 'required|in:native,clipboard']);
        $cart = $this->cart($request);

        DB::transaction(function () use ($cart, $supplier, $request, $validated, $supplierDiscountPrice): void {
            $cartItems = $this->supplierItems($cart, $supplier)->with('offer.medicine')->get();
            abort_if($cartItems->isEmpty(), 404);
            $this->validateCurrentItems($cartItems);
            $supplierRequest = SupplierRequest::create([
                'user_id' => $request->user()->id,
                'buyer_organization_id' => $request->user()->organization_id,
                'supplier_organization_id' => $supplier->id,
                'buyer_name' => $request->user()->organization?->name ?? $request->user()->name,
                'supplier_name' => $supplier->name,
                'item_count' => $cartItems->sum('quantity'),
                'total' => $this->totalFor($cartItems, $supplierDiscountPrice),
                'shared_via' => $validated['shared_via'],
                'shared_at' => now(),
            ]);

            foreach ($cartItems as $cartItem) {
                $lineTotal = $supplierDiscountPrice->lineTotal($cartItem->quantity, (string) $cartItem->unit_price);
                $supplierRequest->items()->create([
                    'offer_id' => $cartItem->offer_id,
                    'medicine_id' => $cartItem->offer->medicine_id,
                    'product_name' => $cartItem->snapshot['medicine'] ?? $cartItem->offer->medicine->name,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $cartItem->unit_price,
                    'line_total' => $lineTotal,
                    'snapshot' => $cartItem->snapshot,
                ]);
            }

            CartItem::query()->whereKey($cartItems->modelKeys())->delete();
        });

        return redirect()->route('cart')->with('success', 'Заявка сохранена в архив и удалена из корзины.');
    }

    public function checkout(Request $request, SupplierDiscountPrice $supplierDiscountPrice): RedirectResponse
    {
        abort_unless($request->user()->canBuy(), 403);
        $cart = $this->cart($request)->load('items.offer');
        abort_if($cart->items->isEmpty(), 422);
        DB::transaction(function () use ($cart, $request, $supplierDiscountPrice): void {
            $this->validateCurrentItems($cart->items);
            $checkoutId = DB::table('checkouts')->insertGetId(['buyer_organization_id' => $request->user()->organization_id, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($cart->items->groupBy(fn (CartItem $cartItem) => $cartItem->offer->organization_id) as $supplierOrganizationId => $cartItems) {
                $total = $cartItems->reduce(
                    fn (string $total, CartItem $cartItem): string => bcadd($total, $supplierDiscountPrice->lineTotal($cartItem->quantity, (string) $cartItem->unit_price), 2),
                    '0.00',
                );
                $order = Order::create(['checkout_id' => $checkoutId, 'buyer_organization_id' => $request->user()->organization_id, 'supplier_organization_id' => $supplierOrganizationId, 'total' => $total]);
                foreach ($cartItems as $cartItem) {
                    OrderItem::create(['order_id' => $order->id, 'offer_id' => $cartItem->offer_id, 'medicine_id' => $cartItem->offer->medicine_id, 'quantity' => $cartItem->quantity, 'unit_price' => $cartItem->unit_price, 'snapshot' => $cartItem->snapshot]);
                }
            }
            $cart->items()->delete();
        });

        return redirect()->route('orders.index')->with('success', 'Заказы переданы поставщикам.');
    }

    private function isCurrent(Offer $offer): bool
    {
        return $offer->isCurrentAvailable();
    }

    private function supplierItems(Cart $cart, Organization $supplier): HasMany
    {
        return $cart->items()->whereHas('offer', fn ($query) => $query->where('organization_id', $supplier->id));
    }

    private function validateCurrentItems(Collection $cartItems): void
    {
        foreach ($cartItems as $cartItem) {
            $offer = Offer::query()->lockForUpdate()->findOrFail($cartItem->offer_id);
            if (! $this->isCurrent($offer) || ($offer->quantity !== null && $cartItem->quantity > (float) $offer->quantity)) {
                throw ValidationException::withMessages(['cart' => 'В корзине есть устаревшее предложение или недостаточный остаток. Обновите корзину.']);
            }
        }
    }

    private function totalFor(Collection $cartItems, SupplierDiscountPrice $supplierDiscountPrice): string
    {
        return $cartItems->reduce(
            fn (string $total, CartItem $cartItem): string => bcadd($total, $supplierDiscountPrice->lineTotal($cartItem->quantity, (string) $cartItem->unit_price), 2),
            '0.00',
        );
    }
}
