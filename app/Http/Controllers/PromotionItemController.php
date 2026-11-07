<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePromotionItemRequest;
use App\Models\Offer;
use App\Models\Promotion;
use App\Models\PromotionItem;
use App\Services\AuditLogger;
use App\Services\PromotionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PromotionItemController extends Controller
{
    public function store(Promotion $promotion, SavePromotionItemRequest $request, PromotionWorkflow $workflow, AuditLogger $audit): RedirectResponse
    {
        $workflow->locked($promotion, function (Promotion $current) use ($request, $workflow, $audit): void {
            $this->authorize('update', $current);
            $data = $request->validated();
            if ($data['source_offer_id'] ?? null) {
                $offer = Offer::query()->whereKey($data['source_offer_id'])->where('organization_id', $current->organization_id)->currentImport()->with('medicine')->firstOrFail();
                $data = array_replace($data, ['name' => $offer->source_name ?: $offer->medicine->name, 'dosage' => $offer->medicine->dosage, 'form' => $offer->medicine->form,
                    'packaging' => $offer->imported_unit, 'supplier_product_id' => $offer->supplier_product_id]);
            }
            $item = $current->items()->create($data);
            $audit->log('promotion.item_created', $item, [], $item->toArray(), $request->user());
            $workflow->edited($current, $request->user());
        });

        return back()->with('success', 'Позиция добавлена.');
    }

    public function update(Promotion $promotion, PromotionItem $item, SavePromotionItemRequest $request, PromotionWorkflow $workflow, AuditLogger $audit): RedirectResponse
    {
        $workflow->locked($promotion, function (Promotion $current) use ($item, $request, $workflow, $audit): void {
            $item = $current->items()->lockForUpdate()->findOrFail($item->id);
            $this->authorize('update', $item);
            $before = $item->toArray();
            $data = $request->safe()->except('source_offer_id');
            $item->fill($data);
            $item->terms_version++;
            $item->save();
            $audit->log('promotion.item_updated', $item, $before, $item->toArray(), $request->user());
            $workflow->edited($current, $request->user());
        });

        return back()->with('success', 'Позиция сохранена.');
    }

    public function destroy(Promotion $promotion, PromotionItem $item, Request $request, PromotionWorkflow $workflow, AuditLogger $audit): RedirectResponse
    {
        $workflow->locked($promotion, function (Promotion $current) use ($item, $request, $workflow, $audit): void {
            $item = $current->items()->lockForUpdate()->findOrFail($item->id);
            $this->authorize('delete', $item);
            $before = $item->toArray();
            $item->delete();
            $audit->log('promotion.item_deleted', $item, $before, [], $request->user());
            $workflow->edited($current, $request->user());
        });

        return back()->with('success', 'Позиция удалена.');
    }
}
