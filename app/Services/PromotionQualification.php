<?php

namespace App\Services;

use App\Enums\OrganizationType;
use App\Enums\PromotionConditionMatch;
use App\Enums\PromotionPurchaseScope;
use App\Enums\PromotionStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Promotion;
use App\Models\PromotionContribution;
use App\Models\User;
use Carbon\CarbonImmutable;

class PromotionQualification
{
    public const array COUNTED_STATUSES = ['confirmed', 'preparing', 'ready', 'delivering', 'received_by_customer'];

    /** Capture once, inside checkout's transaction and supplier locks. Never replay historical orders. */
    public function capture(Order $order, User $buyer): void
    {
        if (! $buyer->isPharmacyMode() || $buyer->activeOrganizationId() !== $order->buyer_organization_id
            || $buyer->activeOrganization()?->type !== OrganizationType::Pharmacy) {
            return;
        }

        $placedAt = $order->created_at->toImmutable()->timezone('Asia/Dushanbe');
        $promotions = Promotion::query()->where('organization_id', $order->supplier_organization_id)
            ->whereNotNull('purchase_scope')->where('status', PromotionStatus::Published)
            ->whereDate('starts_on', '<=', $placedAt->toDateString())->whereDate('ends_on', '>=', $placedAt->toDateString())
            ->whereHas('organization', fn ($query) => $query->where('status', 'active')->where('setup_required', false)->where('price_list_hidden', false))
            ->orderBy('id')->lockForUpdate()->get();
        $lines = $order->items()->get();

        foreach ($promotions as $promotion) {
            $startsAt = CarbonImmutable::parse($promotion->starts_on->toDateString(), 'Asia/Dushanbe');
            $endsAt = CarbonImmutable::parse($promotion->ends_on->toDateString(), 'Asia/Dushanbe')->addDay();
            if ($promotion->first_published_at === null || $placedAt->lt($startsAt)
                || $placedAt->lt($promotion->first_published_at) || $placedAt->gte($endsAt)) {
                continue;
            }
            $qualifyingLines = $promotion->purchase_scope === PromotionPurchaseScope::Company
                ? $lines : $lines->where('promotion_id', $promotion->id);
            if ($qualifyingLines->isEmpty()) {
                continue;
            }
            $amount = $qualifyingLines->reduce(fn (string $total, OrderItem $line): string => bcadd($total, bcmul((string) $line->quantity, $line->unit_price, 2), 2), '0.00');
            $promotion->contributions()->firstOrCreate(['order_id' => $order->id], [
                'pharmacy_organization_id' => $order->buyer_organization_id, 'amount' => $amount,
            ]);
        }
    }

    /** @return array{qualifying_total: string, largest_order_value: string, qualifying_order_count: int, is_eligible: bool} */
    public function progress(Promotion $promotion, int $pharmacyId): array
    {
        $amounts = $promotion->contributions()->where('pharmacy_organization_id', $pharmacyId)
            ->whereHas('order', fn ($query) => $query->whereIn('status', self::COUNTED_STATUSES))->pluck('amount');
        $total = '0.00';
        $largest = '0.00';
        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 2);
            if (bccomp((string) $amount, $largest, 2) > 0) {
                $largest = bcadd((string) $amount, '0', 2);
            }
        }
        $matches = [];
        if ($promotion->minimum_total !== null) {
            $matches[] = bccomp($total, $promotion->minimum_total, 2) >= 0;
        }
        if ($promotion->minimum_order_value !== null) {
            $matches[] = bccomp($largest, $promotion->minimum_order_value, 2) >= 0;
        }
        if ($promotion->minimum_order_count !== null) {
            $matches[] = $amounts->count() >= $promotion->minimum_order_count;
        }
        $eligible = $promotion->purchase_scope !== null && $matches !== []
            && ($promotion->condition_match === PromotionConditionMatch::All ? ! in_array(false, $matches, true) : in_array(true, $matches, true));

        return ['qualifying_total' => $total, 'largest_order_value' => $largest, 'qualifying_order_count' => $amounts->count(), 'is_eligible' => $eligible];
    }

    /** Recompute under the supplier lock, retaining first qualification even after reversal. */
    public function recompute(Promotion $promotion, int $pharmacyId): void
    {
        $progress = $this->progress($promotion, $pharmacyId);
        $participant = $promotion->participants()->where('pharmacy_organization_id', $pharmacyId)->lockForUpdate()->first();
        if ($participant === null && ! $progress['is_eligible']) {
            return;
        }
        if ($participant === null) {
            $participant = $promotion->participants()->make(['pharmacy_organization_id' => $pharmacyId, 'qualified_at' => now()]);
        }
        $participant->fill($progress)->save();
    }

    /** Called after changing order status, with its supplier locked. */
    public function recomputeForOrder(Order $order): void
    {
        $promotionIds = PromotionContribution::query()->where('order_id', $order->id)->pluck('promotion_id');
        foreach (Promotion::query()->whereIn('id', $promotionIds)->orderBy('id')->lockForUpdate()->get() as $promotion) {
            $this->recompute($promotion, $order->buyer_organization_id);
        }
    }
}
