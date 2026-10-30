<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CartMaintenance
{
    public function __construct(private AuditLogger $audit, private SupplierDiscountPrice $prices) {}

    /**
     * Serialize customer actions, including first cart creation. Activation locks the
     * supplier before its items; customer actions must use that same lock order.
     *
     * @param  array<int, int>  $supplierIds
     */
    public function transaction(User $user, array $supplierIds, Closure $action): mixed
    {
        return DB::transaction(function () use ($user, $supplierIds, $action): mixed {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $cart = Cart::query()->lockForUpdate()->firstOrCreate(['user_id' => $user->id]);
            $suppliers = Organization::query()->where(function ($query) use ($cart, $supplierIds): void {
                $query->whereIn('id', Offer::query()->select('organization_id')->whereIn('id', $cart->items()->select('offer_id')))
                    ->orWhereIn('id', $supplierIds);
            })->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $items = $cart->items()->with('offer')->orderBy('id')->lockForUpdate()->get();
            foreach ($items as $item) {
                $this->reconcile($item, $suppliers[$item->offer->organization_id]);
            }

            return $action();
        }, 3);
    }

    /** Caller holds the supplier lock inside the activation transaction. */
    public function replaceSupplierOffers(Organization $supplier, ?User $actor = null): void
    {
        CartItem::query()->whereHas('offer', fn ($query) => $query->where('organization_id', $supplier->id))
            ->with('offer')->orderBy('id')->lockForUpdate()->chunkById(200, function ($items) use ($supplier, $actor): void {
                foreach ($items as $item) {
                    $this->reconcile($item, $supplier, $actor);
                }
            });
    }

    /** Caller holds the supplier and item locks. Never guesses identity from medicine/name. */
    public function reconcile(CartItem $item, Organization $supplier, ?User $actor = null): void
    {
        $offer = $item->offer;
        $reason = null;
        if ($offer->price_list_import_id === null || $offer->price_list_import_id !== $supplier->active_price_list_import_id) {
            $candidates = $offer->supplier_product_id === null ? collect() : Offer::query()
                ->where('organization_id', $supplier->id)
                ->where('price_list_import_id', $supplier->active_price_list_import_id)
                ->whereNotNull('price_list_import_id')
                ->where('supplier_product_id', $offer->supplier_product_id)
                ->orderBy('id')->lockForUpdate()->get();
            if ($candidates->count() > 1) {
                $candidates = $candidates->filter(fn (Offer $candidate): bool => $candidate->batch === $offer->batch
                    && $candidate->expires_at?->toDateString() === $offer->expires_at?->toDateString());
            }
            $replacement = $candidates->count() === 1 ? $candidates->first() : null;
            $reason = $candidates->count() > 1 ? 'ambiguous_replacement' : 'missing_replacement';
            if ($replacement !== null && CartItem::query()->where('cart_id', $item->cart_id)->where('offer_id', $replacement->id)->whereKeyNot($item->id)->lockForUpdate()->first() !== null) {
                $reason = 'replacement_already_in_cart';
                $replacement = null;
            }
            if ($replacement !== null) {
                $before = $item->toArray();
                $item->original_snapshot ??= array_merge($item->snapshot, ['unit_price' => $item->unit_price, 'offer_id' => $item->offer_id]);
                $item->offer_change = ['previous_offer_id' => $offer->id, 'previous_unit_price' => $item->unit_price];
                $item->offer_id = $replacement->id;
                $effectivePrice = $this->prices->effectivePrice((string) $replacement->price, $item->snapshot['supplier_discount_percent'] ?? null);
                $comparison = bccomp($effectivePrice, (string) $item->unit_price, 2);
                $item->proposed_unit_price = $comparison > 0 ? $effectivePrice : null;
                if ($comparison <= 0) {
                    $item->unit_price = $effectivePrice;
                }
                $item->offer_change = $comparison < 0 ? $item->offer_change : null;
                $item->snapshot = array_replace($item->snapshot, [
                    'price' => $replacement->price, 'raw_price' => $replacement->price,
                    'source_name' => $replacement->source_name, 'batch' => $replacement->batch,
                    'expires_at' => $replacement->expires_at?->toDateString(),
                ]);
                $item->save();
                $item->setRelation('offer', $replacement);
                $this->audit->log('cart.item_remapped', $item, $before, $item->toArray(), $actor);
                if ($comparison !== 0) {
                    $this->audit->log($comparison < 0 ? 'cart.price_decreased' : 'cart.price_increase_pending', $item, $before, $item->toArray(), $actor);
                }
                $offer = $replacement;
                $reason = null;
            }
        }
        $reason ??= $supplier->price_list_hidden ? 'supplier_hidden' : null;
        if ($reason === null && $offer->quantity !== null) {
            $reason = (float) $offer->quantity <= 0 ? 'out_of_stock'
                : ((float) $offer->quantity < $item->quantity ? 'insufficient_stock' : null);
        }
        if ($item->availability_reason !== $reason) {
            $before = $item->toArray();
            $item->update(['availability_reason' => $reason]);
            $this->audit->log($reason === null ? 'cart.item_available' : 'cart.item_unavailable', $item, $before, $item->toArray(), $actor);
        }
    }

    /** @param Collection<int, CartItem> $items @return Collection<int, CartItem> */
    public function eligible(Collection $items): Collection
    {
        return $items->filter(fn (CartItem $item): bool => $item->isEligible());
    }

    /** @param Collection<int, CartItem> $items @param array<string, mixed> $context */
    public function remove(Cart $cart, Collection $items, string $event, array $context = []): void
    {
        $this->audit->log($event, $cart, ['user_id' => $cart->user_id, 'items' => $items->map(fn (CartItem $item): array => $item->attributesToArray())->all()], $context);
        CartItem::query()->whereKey($items->pluck('id')->all())->delete();
    }
}
