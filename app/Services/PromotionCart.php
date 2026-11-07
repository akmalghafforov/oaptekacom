<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\PharmacySupplierDiscount;
use App\Models\PromotionItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PromotionCart
{
    public function __construct(private SupplierDiscountPrice $prices, private AuditLogger $audit) {}

    public function selectedPercent(PromotionItem $item, User $user): ?string
    {
        if ($item->price_mode !== 'pharmacy_discount' || ! $user->isPharmacyMode()) {
            return null;
        }
        $agreement = PharmacySupplierDiscount::query()->where('pharmacy_organization_id', $user->activeOrganizationId())
            ->where('supplier_organization_id', $item->promotion->organization_id)->value('supplier_discount_percent');

        return $this->prices->selectedPercent($agreement, $item->promotion->organization->default_discount_percent);
    }

    /** @return array<string, mixed> */
    public function snapshot(PromotionItem $item, ?string $percent, ?int $pharmacyId): array
    {
        return [
            'medicine' => $item->displayName(), 'supplier' => $item->promotion->organization->name,
            'promotion_id' => $item->promotion_id, 'promotion_item_id' => $item->id, 'promotion_title' => $item->promotion->title,
            'name' => $item->name, 'dosage' => $item->dosage, 'form' => $item->form, 'packaging' => $item->packaging,
            'source_offer_id' => $item->source_offer_id, 'supplier_product_id' => $item->supplier_product_id,
            'price' => $item->price, 'raw_price' => $item->price, 'price_mode' => $item->price_mode,
            'supplier_discount_percent' => $percent, 'pharmacy_organization_id' => $pharmacyId,
            'starts_on' => $item->promotion->starts_on->toDateString(), 'ends_on' => $item->promotion->ends_on->toDateString(),
            'minimum_quantity' => $item->minimum_quantity, 'discount_rule' => $item->discount_rule,
            'conditions' => $item->promotion->conditions, 'terms_version' => $this->version($item, $percent),
        ];
    }

    public function version(PromotionItem $item, ?string $percent): string
    {
        return hash('sha256', json_encode([$item->promotion_id, $item->id, $item->promotion->terms_version, $item->terms_version, $percent], JSON_THROW_ON_ERROR));
    }

    public function add(Cart $cart, PromotionItem $item, int $quantity, User $user): CartItem
    {
        $row = $cart->items()->where('promotion_item_id', $item->id)->lockForUpdate()->first();
        if ($row !== null && ($row->offer_change !== null || $row->accepted_terms_version !== $this->version($item, $row->snapshot['supplier_discount_percent'] ?? null))) {
            throw ValidationException::withMessages(['cart' => 'Условия акции изменились. Подтвердите их в корзине.']);
        }
        $totalQuantity = ($row?->quantity ?? 0) + $quantity;
        if ($totalQuantity > 999999 || $item->availabilityReason($totalQuantity) !== null) {
            throw ValidationException::withMessages(['quantity' => 'Позиция недоступна, не достигнут минимум или превышен остаток.']);
        }
        if ($row === null) {
            $percent = $this->selectedPercent($item, $user);
            $snapshot = $this->snapshot($item, $percent, $user->isPharmacyMode() ? $user->activeOrganizationId() : null);
            $row = $cart->items()->make(['promotion_item_id' => $item->id, 'offer_id' => null,
                'unit_price' => $this->prices->effectivePrice($item->price, $percent), 'snapshot' => $snapshot,
                'accepted_terms_version' => $snapshot['terms_version']]);
        }
        $row->quantity = $totalQuantity;
        $row->save();
        $this->audit->log('cart.promotion_added', $row, [], $row->toArray(), $user);

        return $row;
    }

    public function reconcile(CartItem $row, ?string $agreementPercent = null): void
    {
        $item = $row->promotionItem;
        $savedPercent = $row->offer_change['terms']['supplier_discount_percent'] ?? $row->snapshot['supplier_discount_percent'] ?? null;
        if (($row->snapshot['price_mode'] ?? null) !== 'pharmacy_discount'
            && ($row->offer_change['terms']['price_mode'] ?? null) !== 'pharmacy_discount'
            && ($row->snapshot['pharmacy_organization_id'] ?? null) !== null) {
            $savedPercent = $this->prices->selectedPercent(null, $item->promotion->organization->default_discount_percent);
        }
        $percent = $item->price_mode === 'pharmacy_discount' ? ($agreementPercent ?? $savedPercent) : null;
        $version = $this->version($item, $percent);
        $before = $row->toArray();
        $row->availability_reason = $item->availabilityReason($row->quantity);
        if ($row->accepted_terms_version !== $version) {
            $row->proposed_unit_price = $item->price === null ? null : $this->prices->effectivePrice($item->price, $percent);
            $row->offer_change = ['promotion_terms_version' => $version, 'terms' => $this->snapshot($item, $percent, $row->snapshot['pharmacy_organization_id'] ?? null)];
        } else {
            $row->proposed_unit_price = null;
            $row->offer_change = null;
        }
        if ($row->isDirty()) {
            $row->original_snapshot ??= $row->snapshot + ['unit_price' => $row->unit_price];
            $row->save();
            $this->audit->log('cart.promotion_reconciled', $row, $before, $row->toArray());
        }
    }

    public function accept(CartItem $row, string $version, User $user): void
    {
        if ($row->availability_reason !== null || ! $row->offer_change || ! hash_equals($row->offer_change['promotion_terms_version'], $version)) {
            throw ValidationException::withMessages(['cart' => 'Условия изменились или позиция недоступна. Обновите корзину.']);
        }
        $before = $row->toArray();
        $row->update(['unit_price' => $row->proposed_unit_price, 'snapshot' => $row->offer_change['terms'], 'accepted_terms_version' => $version,
            'proposed_unit_price' => null, 'offer_change' => null]);
        $this->audit->log('cart.promotion_terms_accepted', $row, $before, $row->toArray(), $user);
    }
}
