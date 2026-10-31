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
use App\Services\AuditLogger;
use App\Services\CartMaintenance;
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
    public function __construct(private CartMaintenance $maintenance) {}

    /**
     * Route bindings may predate a concurrent activation. Reload them only after
     * acquiring the supplier locks, and keep validation and writes in one transaction.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function callAction(string $method, array $parameters): mixed
    {
        $supplierIds = [];
        foreach ($parameters as $parameter) {
            if ($parameter instanceof Offer) {
                $supplierIds[] = $parameter->organization_id;
            } elseif ($parameter instanceof Organization) {
                $supplierIds[] = $parameter->id;
            }
        }

        return $this->maintenance->transaction(request()->user(), $supplierIds, function () use ($method, $parameters): mixed {
            foreach ($parameters as $key => $parameter) {
                if ($parameter instanceof CartItem || $parameter instanceof Offer || $parameter instanceof Organization) {
                    $parameters[$key] = $parameter->newQuery()->lockForUpdate()->findOrFail($parameter->id);
                }
            }

            return $this->{$method}(...array_values($parameters));
        });
    }

    private function cart(Request $request): Cart
    {
        return Cart::firstOrCreate(['user_id' => $request->user()->id]);
    }

    public function show(Request $request): View
    {
        abort_unless($request->user()->canBuy(), 403);
        $cart = $this->cart($request);

        $cart->setRelation('items', $cart->items()->with('offer.medicine', 'offer.organization')->lockForUpdate()->get());
        $supplierGroups = $cart->items
            ->groupBy(fn (CartItem $item): int => $item->offer->organization_id)
            ->map(function ($items): array {
                $eligible = $this->maintenance->eligible($items);
                $total = $eligible->reduce(
                    fn (string $total, CartItem $item): string => bcadd($total, bcmul((string) $item->unit_price, (string) $item->quantity, 2), 2),
                    '0.00',
                );

                return ['supplier' => $items->first()->offer->organization, 'items' => $items, 'total' => $total];
            });

        return view('orders.cart', ['cart' => $cart, 'supplierGroups' => $supplierGroups]);
    }

    public function add(Offer $offer, Request $request, SupplierDiscountPrice $supplierDiscountPrice): RedirectResponse|JsonResponse
    {
        $this->authorize('view', $offer);
        abort_unless($request->user()->canBuy() && $this->isCurrent($offer), 404);
        $validated = $request->validate(['quantity' => 'nullable|integer|min:1|max:999999']);
        $cartItem = CartItem::query()->lockForUpdate()->firstOrNew(['cart_id' => $this->cart($request)->id, 'offer_id' => $offer->id]);
        if ($cartItem->proposed_unit_price !== null) {
            throw ValidationException::withMessages(['cart' => 'Подтвердите новую цену кнопкой «Принять цену» в корзине.']);
        }
        $cartItem->quantity = ($cartItem->exists ? $cartItem->quantity : 0) + ($validated['quantity'] ?? 1);
        if ($offer->quantity !== null && $cartItem->quantity > (float) $offer->quantity) {
            throw ValidationException::withMessages(['quantity' => 'Запрошенное количество превышает остаток поставщика.']);
        }
        if (! $cartItem->exists) {
            $supplierDiscountPercent = $request->user()->isPharmacyMode()
                ? PharmacySupplierDiscount::query()
                    ->where('pharmacy_organization_id', $request->user()->activeOrganizationId())
                    ->where('supplier_organization_id', $offer->organization_id)
                    ->value('supplier_discount_percent')
                : null;
            if ($request->user()->isPharmacyMode()) {
                $supplierDiscountPercent = $supplierDiscountPrice->selectedPercent($supplierDiscountPercent, (string) $offer->organization->default_discount_percent);
            }
            $unitPrice = $supplierDiscountPrice->effectivePrice((string) $offer->price, $supplierDiscountPercent);

            $cartItem->unit_price = $unitPrice;
            $cartItem->snapshot = [
                'medicine' => $offer->medicine->name,
                'supplier' => $offer->organization->name,
                'price' => $offer->price,
                'raw_price' => $offer->price,
                'supplier_discount_percent' => $supplierDiscountPercent,
                'pharmacy_organization_id' => $request->user()->isPharmacyMode() ? $request->user()->activeOrganizationId() : null,
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
                'item' => ['offer_id' => $offer->id, 'quantity' => $cartItem->quantity, 'remove_url' => route('cart.items.destroy', $cartItem)],
            ]);
        }

        return back()->with('success', 'Товар добавлен в корзину.');
    }

    public function update(CartItem $item, Request $request, SupplierDiscountPrice $supplierDiscountPrice): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $item->cart);
        $validated = $request->validate(['quantity' => 'required|integer|min:1|max:999999']);
        if (! $this->isCurrent($item->offer) || ($item->offer->quantity !== null && $validated['quantity'] > (float) $item->offer->quantity)) {
            throw ValidationException::withMessages(['quantity' => 'Предложение устарело или нужное количество больше остатка.']);
        }
        $item->update($validated);
        $this->maintenance->reconcile($item, $item->offer->organization, $request->user());

        if ($request->expectsJson()) {
            $cartItems = $item->cart->items()->with('offer.organization')->lockForUpdate()->get();
            $supplierItems = $this->maintenance->eligible($cartItems)->filter(fn (CartItem $cartItem): bool => $cartItem->offer->organization_id === $item->offer->organization_id);
            $supplierTotal = $this->totalFor($supplierItems, $supplierDiscountPrice);
            $sendableItems = $this->maintenance->eligible($cartItems);
            $cartTotal = $this->totalFor($sendableItems, $supplierDiscountPrice);
            $supplierShareText = "Заявка OAPTEKA\nПоставщик: {$item->offer->organization->name}\n".$supplierItems->map(fn (CartItem $cartItem): string => "{$cartItem->snapshot['medicine']} — {$cartItem->quantity} × {$cartItem->unit_price} TJS")->implode("\n")."\nИтого: {$supplierTotal} TJS";
            $allShareText = "Заявки OAPTEKA\n".$sendableItems->groupBy(fn (CartItem $cartItem): int => $cartItem->offer->organization_id)->map(function ($items) use ($supplierDiscountPrice): string {
                $total = $this->totalFor($items, $supplierDiscountPrice);

                return $items->first()->offer->organization->name."\n".$items->map(fn (CartItem $cartItem): string => "{$cartItem->snapshot['medicine']} — {$cartItem->quantity} × {$cartItem->unit_price} TJS")->implode("\n")."\nИтого: {$total} TJS";
            })->implode("\n\n")."\nОбщий итог: {$cartTotal} TJS";

            return response()->json([
                'item' => ['id' => $item->id, 'quantity' => (int) $item->quantity, 'line_total' => $supplierDiscountPrice->lineTotal($item->quantity, (string) $item->unit_price)],
                'supplier' => ['id' => $item->offer->organization_id, 'total' => $supplierTotal, 'share_text' => $supplierShareText],
                'cart' => ['total' => $cartTotal, 'total_quantity' => (int) $cartItems->sum('quantity'), 'share_text' => $allShareText, 'excluded_count' => $cartItems->count() - $sendableItems->count()],
                'availability' => $cartItems->map(fn (CartItem $cartItem): array => ['id' => $cartItem->id, 'warning' => $cartItem->availabilityWarning()])->values()->all(),
            ]);
        }

        return back();
    }

    public function destroy(CartItem $item, Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $item->cart);
        $cart = $item->cart;
        $offerId = $item->offer_id;
        $this->maintenance->remove($cart, collect([$item]), 'cart.item_deleted');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Позиция удалена из корзины.',
                'cart' => ['total_quantity' => (int) $cart->items()->sum('quantity')],
                'item' => ['offer_id' => $offerId],
            ]);
        }

        return back()->with('success', 'Позиция удалена из корзины.');
    }

    public function destroySupplier(Organization $supplier, Request $request): RedirectResponse
    {
        abort_unless($request->user()->canBuy(), 403);
        $cart = $this->cart($request);
        $items = $this->supplierItems($cart, $supplier)->lockForUpdate()->get();
        abort_if($items->isEmpty(), 404);
        $this->maintenance->remove($cart, $items, 'cart.supplier_cleared', ['supplier_id' => $supplier->id]);

        return back()->with('success', 'Заявка поставщику очищена.');
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canBuy(), 403);
        $cart = $this->cart($request);
        $this->maintenance->remove($cart, $cart->items()->lockForUpdate()->get(), 'cart.cleared');

        return redirect()->route('cart')->with('success', 'Корзина очищена.');
    }

    public function checkoutSupplier(Organization $supplier, Request $request, SupplierDiscountPrice $supplierDiscountPrice): RedirectResponse
    {
        abort_unless($request->user()->canBuy(), 403);
        if (! config('orders.placement_enabled')) {
            return redirect()->route('cart')->with('warning', 'Оформление заказов временно недоступно. Вы можете поделиться заявкой.');
        }

        $cart = $this->cart($request);

        DB::transaction(function () use ($cart, $supplier, $request, $supplierDiscountPrice): void {
            $cartItems = $this->supplierItems($cart, $supplier)->with('offer')->lockForUpdate()->get();
            abort_if($cartItems->isEmpty(), 404);
            $cartItems = $this->sendableItems($cartItems);
            $total = $this->totalFor($cartItems, $supplierDiscountPrice);
            $checkoutId = DB::table('checkouts')->insertGetId(['buyer_organization_id' => $request->user()->activeOrganizationId(), 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $order = Order::create(['checkout_id' => $checkoutId, 'buyer_organization_id' => $request->user()->activeOrganizationId(), 'supplier_organization_id' => $supplier->id, 'total' => $total]);

            foreach ($cartItems as $cartItem) {
                OrderItem::create(['order_id' => $order->id, 'offer_id' => $cartItem->offer_id, 'medicine_id' => $cartItem->offer->medicine_id, 'quantity' => $cartItem->quantity, 'unit_price' => $cartItem->unit_price, 'snapshot' => $cartItem->snapshot]);
            }

            $this->maintenance->remove($cart, $cartItems, 'cart.checkout_removed', ['checkout_id' => $checkoutId]);
        });

        return redirect()->route('orders.index')->with('success', 'Заказ передан поставщику.');
    }

    public function shareSupplier(Organization $supplier, Request $request): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->canBuy(), 403);
        abort_if($supplier->price_list_hidden, 404);
        $request->validate(['shared_via' => 'required|in:native,clipboard']);
        $cart = $this->cart($request);
        $items = $this->supplierItems($cart, $supplier)->lockForUpdate()->get();
        abort_if($items->isEmpty(), 404);
        $items = $this->sendableItems($items);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Заявкой поделились. Позиции остались в корзине.', 'share_text' => $this->shareText($items), 'excluded' => $this->supplierItems($cart, $supplier)->count() - $items->count()]);
        }

        return redirect()->route('cart')->with('success', 'Заявкой поделились. Позиции остались в корзине.');
    }

    public function archiveSupplier(Organization $supplier, Request $request, SupplierDiscountPrice $supplierDiscountPrice): RedirectResponse
    {
        abort_unless($request->user()->canBuy(), 403);
        $cart = $this->cart($request);

        DB::transaction(function () use ($cart, $supplier, $request, $supplierDiscountPrice): void {
            $cartItems = $this->supplierItems($cart, $supplier)->with('offer.medicine')->lockForUpdate()->get();
            abort_if($cartItems->isEmpty(), 404);
            $cartItems = $this->sendableItems($cartItems);
            $supplierRequest = SupplierRequest::create([
                'user_id' => $request->user()->id,
                'buyer_organization_id' => $request->user()->activeOrganizationId(),
                'supplier_organization_id' => $supplier->id,
                'buyer_name' => $request->user()->activeOrganization()?->name ?? $request->user()->name,
                'supplier_name' => $supplier->name,
                'item_count' => $cartItems->sum('quantity'),
                'total' => $this->totalFor($cartItems, $supplierDiscountPrice),
                'shared_via' => 'archive',
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

            $this->maintenance->remove($cart, $cartItems, 'cart.archived', ['supplier_request_id' => $supplierRequest->id]);
        });

        return redirect()->route('cart')->with('success', 'Доступные позиции сохранены в архив и удалены из корзины. Недоступные позиции сохранены.');
    }

    public function checkout(Request $request, SupplierDiscountPrice $supplierDiscountPrice): RedirectResponse
    {
        abort_unless($request->user()->canBuy(), 403);
        if (! config('orders.placement_enabled')) {
            return redirect()->route('cart')->with('warning', 'Оформление заказов временно недоступно. Вы можете поделиться заявкой.');
        }

        $cart = $this->cart($request);
        $cart->setRelation('items', $cart->items()->with('offer.organization')->lockForUpdate()->get());
        $cart->setRelation('items', $this->sendableItems($cart->items));
        abort_if($cart->items->isEmpty(), 422);
        DB::transaction(function () use ($cart, $request, $supplierDiscountPrice): void {
            $checkoutId = DB::table('checkouts')->insertGetId(['buyer_organization_id' => $request->user()->activeOrganizationId(), 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($cart->items->groupBy(fn (CartItem $cartItem) => $cartItem->offer->organization_id) as $supplierOrganizationId => $cartItems) {
                $total = $cartItems->reduce(
                    fn (string $total, CartItem $cartItem): string => bcadd($total, $supplierDiscountPrice->lineTotal($cartItem->quantity, (string) $cartItem->unit_price), 2),
                    '0.00',
                );
                $order = Order::create(['checkout_id' => $checkoutId, 'buyer_organization_id' => $request->user()->activeOrganizationId(), 'supplier_organization_id' => $supplierOrganizationId, 'total' => $total]);
                foreach ($cartItems as $cartItem) {
                    OrderItem::create(['order_id' => $order->id, 'offer_id' => $cartItem->offer_id, 'medicine_id' => $cartItem->offer->medicine_id, 'quantity' => $cartItem->quantity, 'unit_price' => $cartItem->unit_price, 'snapshot' => $cartItem->snapshot]);
                }
            }
            $this->maintenance->remove($cart, $cart->items, 'cart.checkout_removed', ['checkout_id' => $checkoutId]);
        });

        return redirect()->route('orders.index')->with('success', 'Заказы переданы поставщикам.');
    }

    private function isCurrent(Offer $offer): bool
    {
        $supplier = Organization::query()->lockForUpdate()->findOrFail($offer->organization_id);

        return $offer->price_list_import_id !== null
            && $offer->price_list_import_id === $supplier->active_price_list_import_id
            && ! $supplier->price_list_hidden
            && ($offer->quantity === null || (float) $offer->quantity > 0);
    }

    private function supplierItems(Cart $cart, Organization $supplier): HasMany
    {
        return $cart->items()->whereHas('offer', fn ($query) => $query->where('organization_id', $supplier->id));
    }

    private function sendableItems(Collection $items): Collection
    {
        $eligible = $this->maintenance->eligible($items);
        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Нет доступных позиций для отправки. Проверьте остатки и подтвердите новые цены.']);
        }
        if ($eligible->count() !== $items->count()) {
            session()->flash('warning', 'Недоступные позиции исключены и сохранены в корзине вместе с количеством.');
        }

        return $eligible;
    }

    private function shareText(Collection $items): string
    {
        $prices = app(SupplierDiscountPrice::class);

        return "Заявки OAPTEKA\n".$items->groupBy(fn (CartItem $item): int => $item->offer->organization_id)->map(function ($group) use ($prices): string {
            return $group->first()->offer->organization->name."\n".$group->map(fn (CartItem $item): string => "{$item->snapshot['medicine']} — {$item->quantity} × {$item->unit_price} TJS")->implode("\n")."\nИтого: ".$this->totalFor($group, $prices).' TJS';
        })->implode("\n\n")."\nОбщий итог: ".$this->totalFor($items, $prices).' TJS';
    }

    public function share(Request $request): JsonResponse
    {
        abort_unless($request->user()->canBuy(), 403);
        $items = $this->cart($request)->items()->with('offer.organization')->lockForUpdate()->get();

        return response()->json(['share_text' => $this->shareText($this->sendableItems($items)), 'excluded' => $items->count() - $this->maintenance->eligible($items)->count()]);
    }

    public function acceptPrice(CartItem $item, Request $request): RedirectResponse
    {
        $this->authorize('update', $item->cart);
        $offer = Offer::query()->lockForUpdate()->findOrFail($item->offer_id);
        $effectivePrice = app(SupplierDiscountPrice::class)->effectivePrice((string) $offer->price, $item->snapshot['supplier_discount_percent'] ?? null);
        $validated = $request->validate(['offer_id' => 'required|integer', 'proposed_unit_price' => 'required|decimal:0,2|min:0']);
        if (bccomp($effectivePrice, (string) $validated['proposed_unit_price'], 2) !== 0
            || $item->offer->price_list_import_id !== $item->offer->organization->active_price_list_import_id
            || $item->offer->price_list_import_id === null
            || (int) $validated['offer_id'] !== $item->offer_id
            || bccomp((string) ($item->proposed_unit_price ?? $item->unit_price), (string) $validated['proposed_unit_price'], 2) !== 0) {
            throw ValidationException::withMessages(['cart' => 'Цена или предложение изменились. Проверьте актуальную цену в корзине.']);
        }
        if ($item->proposed_unit_price !== null) {
            $before = $item->toArray();
            $item->update(['unit_price' => $item->proposed_unit_price, 'proposed_unit_price' => null, 'offer_change' => null]);
            app(AuditLogger::class)->log('cart.price_accepted', $item, $before, $item->toArray(), $request->user());
        }

        return redirect()->route('cart')->with('success', 'Новая цена принята.');
    }

    private function totalFor(Collection $cartItems, SupplierDiscountPrice $supplierDiscountPrice): string
    {
        return $cartItems->reduce(
            fn (string $total, CartItem $cartItem): string => bcadd($total, $supplierDiscountPrice->lineTotal($cartItem->quantity, (string) $cartItem->unit_price), 2),
            '0.00',
        );
    }
}
