<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PharmacySupplierDiscount;
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
                $supplier = $suppliers[$item->offer->organization_id];
                $this->reconcile($item, $supplier);
                $pharmacyId = $this->pharmacyId($item, $user);
                if ($pharmacyId !== null) {
                    $agreement = PharmacySupplierDiscount::query()->where('pharmacy_organization_id', $pharmacyId)
                        ->where('supplier_organization_id', $supplier->id)->value('supplier_discount_percent');
                    if ($agreement !== null) {
                        $this->reprice($item, $agreement);
                    }
                }
            }

            return $action();
        }, 3);
    }

    public function updateAgreement(User $actor, Organization $supplier, ?string $percent): void
    {
        $pharmacyId = $actor->activeOrganizationId();
        DB::transaction(function () use ($actor, $supplier, $percent, $pharmacyId): void {
            $users = User::query()->where(function ($query) use ($pharmacyId): void {
                $query->whereHas('roleAssignments', fn ($query) => $query->where('role', UserRole::Pharmacy)->where('organization_id', $pharmacyId))
                    ->orWhere(fn ($query) => $query->where('role', UserRole::Pharmacy)->where('organization_id', $pharmacyId));
            })->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $carts = Cart::query()->whereIn('user_id', $users->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $supplier = Organization::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $discount = PharmacySupplierDiscount::query()->where('pharmacy_organization_id', $pharmacyId)
                ->where('supplier_organization_id', $supplier->id)->lockForUpdate()->first();
            $before = $discount?->only('supplier_discount_percent') ?? [];
            if ($percent === null) {
                if ($discount !== null) {
                    $discount->delete();
                    $this->audit->log('pharmacy_supplier_discount.cleared', $discount, $before, [], $actor);
                }
            } else {
                $event = $discount === null ? 'pharmacy_supplier_discount.created' : 'pharmacy_supplier_discount.updated';
                $discount ??= new PharmacySupplierDiscount(['pharmacy_organization_id' => $pharmacyId, 'supplier_organization_id' => $supplier->id]);
                $discount->supplier_discount_percent = $percent;
                $discount->save();
                $this->audit->log($event, $discount, $before, $discount->only('supplier_discount_percent'), $actor);
            }
            $selectedPercent = $this->prices->selectedPercent($percent, $supplier->default_discount_percent);
            $items = CartItem::query()->whereIn('cart_id', $carts->keys())
                ->whereHas('offer', fn ($query) => $query->where('organization_id', $supplier->id))
                ->with('offer')->orderBy('id')->lockForUpdate()->get();
            foreach ($items as $item) {
                if ($this->pharmacyId($item, $users[$carts[$item->cart_id]->user_id]) !== $pharmacyId) {
                    continue;
                }
                $this->reconcile($item, $supplier, $actor);
                $this->reprice($item, $selectedPercent, $actor);
            }
        }, 3);
    }

    private function pharmacyId(CartItem $item, User $user): ?int
    {
        if (array_key_exists('pharmacy_organization_id', $item->snapshot)) {
            return $item->snapshot['pharmacy_organization_id'];
        }
        if ($user->role === UserRole::Pharmacy) {
            return $user->organization_id;
        }
        if (($item->snapshot['supplier_discount_percent'] ?? null) !== null) {
            return $user->roleAssignments()->where('role', UserRole::Pharmacy)->value('organization_id');
        }

        return null;
    }

    private function reprice(CartItem $item, string $percent, ?User $actor = null): void
    {
        $savedPercent = $item->snapshot['supplier_discount_percent'] ?? null;
        if ($savedPercent !== null && bccomp((string) $savedPercent, $percent, 2) === 0) {
            return;
        }
        $before = $item->toArray();
        $price = $this->prices->effectivePrice((string) ($item->snapshot['raw_price'] ?? $item->offer->price), $percent);
        if ($item->proposed_unit_price !== null && bccomp($price, $item->unit_price, 2) > 0) {
            $item->proposed_unit_price = $price;
        } else {
            $item->unit_price = $price;
            $item->proposed_unit_price = null;
            $item->offer_change = null;
        }
        $item->snapshot = array_replace($item->snapshot, ['supplier_discount_percent' => bcadd($percent, '0', 2)]);
        $item->save();
        $this->audit->log('cart.discount_repriced', $item, $before, $item->toArray(), $actor);
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
