<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('orders.cart', ['cart' => $cart->load('items.offer.medicine', 'items.offer.organization'), 'removedStaleItems' => $removed]);
    }

    public function add(Offer $offer, Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('view', $offer);
        abort_unless($request->user()->canBuy() && $this->isCurrent($offer), 404);
        $validated = $request->validate(['quantity' => 'nullable|integer|min:1|max:999']);
        $cartItem = CartItem::firstOrNew(['cart_id' => $this->cart($request)->id, 'offer_id' => $offer->id]);
        $cartItem->quantity = ($cartItem->exists ? $cartItem->quantity : 0) + ($validated['quantity'] ?? 1);
        if ($offer->quantity !== null && $cartItem->quantity > (float) $offer->quantity) {
            throw ValidationException::withMessages(['quantity' => 'Запрошенное количество превышает остаток поставщика.']);
        }
        $cartItem->unit_price = $offer->price;
        $cartItem->snapshot = ['medicine' => $offer->medicine->name, 'supplier' => $offer->organization->name, 'price' => $offer->price, 'source_name' => $offer->source_name, 'batch' => $offer->batch, 'expires_at' => $offer->expires_at?->toDateString()];
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

    public function checkout(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canBuy(), 403);
        $cart = $this->cart($request)->load('items.offer');
        abort_if($cart->items->isEmpty(), 422);
        DB::transaction(function () use ($cart, $request): void {
            foreach ($cart->items as $cartItem) {
                $offer = Offer::query()->lockForUpdate()->findOrFail($cartItem->offer_id);
                if (! $this->isCurrent($offer) || ($offer->quantity !== null && $cartItem->quantity > (float) $offer->quantity)) {
                    throw ValidationException::withMessages(['cart' => 'В корзине есть устаревшее предложение или недостаточный остаток. Обновите корзину.']);
                }
            }
            $checkoutId = DB::table('checkouts')->insertGetId(['buyer_organization_id' => $request->user()->organization_id, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($cart->items->groupBy(fn (CartItem $cartItem) => $cartItem->offer->organization_id) as $supplierOrganizationId => $cartItems) {
                $total = $cartItems->sum(fn (CartItem $cartItem) => $cartItem->quantity * $cartItem->unit_price);
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
}
