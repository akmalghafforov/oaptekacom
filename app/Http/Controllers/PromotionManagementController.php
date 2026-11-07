<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePromotionRequest;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\Promotion;
use App\Services\PromotionWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromotionManagementController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('create', Promotion::class);
        $promotions = Promotion::query()->with('organization')
            ->when(! $request->user()->isAdminMode(), fn ($query) => $query->where('organization_id', $request->user()->activeOrganizationId()))
            ->orderByDesc('id')->paginate(30);

        return view('promotions.manage', compact('promotions'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Promotion::class);

        return view('promotions.form', ['promotion' => new Promotion, 'companies' => $this->companies($request), 'cities' => config('registration.cities')]);
    }

    public function store(SavePromotionRequest $request, PromotionWorkflow $workflow): RedirectResponse
    {
        $promotion = $workflow->save($request->user(), null, $request->safe()->except('poster'), $request->file('poster'));

        return redirect()->route('promotions.edit', $promotion)->withFragment('products')->with('success', 'Черновик акции создан. Добавьте позиции и материалы.');
    }

    public function edit(Promotion $promotion, Request $request): View
    {
        $this->authorize('manage', $promotion);
        $promotion->load('items', 'attachments', 'organization', 'prizes');
        $data = $request->validate(['offer_search' => ['nullable', 'string', 'max:255']]);
        $offerSearch = trim($data['offer_search'] ?? '');
        $companyOffers = Offer::query()->where('organization_id', $promotion->organization_id)->currentImport()->with('medicine');
        $offers = (clone $companyOffers)
            ->when($offerSearch !== '', fn (Builder $query): Builder => $query->where(function (Builder $query) use ($offerSearch): void {
                $query->whereLike('source_name', '%'.$offerSearch.'%')
                    ->orWhereHas('medicine', fn (Builder $medicine): Builder => $medicine->whereLike('name', '%'.$offerSearch.'%'));
            }))
            ->orderBy('source_name')->orderBy('id')->paginate(30, ['*'], 'offers_page')
            ->appends(['offer_search' => $offerSearch])->fragment('copy-products');
        $selectedOfferId = filter_var($request->old('source_offer_id'), FILTER_VALIDATE_INT);
        $selectedOffer = $request->old('_promotion_form') === 'copy' && $selectedOfferId !== false
            ? (clone $companyOffers)->find($selectedOfferId) : null;

        return view('promotions.form', [
            'promotion' => $promotion, 'companies' => $this->companies($request), 'offers' => $offers,
            'offerSearch' => $offerSearch, 'selectedOffer' => $selectedOffer,
            'cities' => array_values(array_unique(array_filter([...config('registration.cities'), $promotion->city]))),
        ]);
    }

    public function update(Promotion $promotion, SavePromotionRequest $request, PromotionWorkflow $workflow): RedirectResponse
    {
        $workflow->save($request->user(), $promotion, $request->safe()->except('poster'), $request->file('poster'));

        return back()->with('success', 'Акция сохранена.');
    }

    public function transition(Promotion $promotion, Request $request, PromotionWorkflow $workflow): RedirectResponse
    {
        $request->merge(['_promotion_form' => 'publication']);
        $data = $request->validate(['action' => ['required', 'in:submit,withdraw,publish,revise,reject'], 'comment' => ['nullable', 'string', 'max:5000']]);
        $workflow->transition($request->user(), $promotion, $data['action'], $data['comment'] ?? null);

        return back()->with('success', 'Статус акции изменён.');
    }

    private function companies(Request $request): Collection
    {
        return Organization::query()->when(! $request->user()->isAdminMode(), fn ($query) => $query->whereKey($request->user()->activeOrganizationId()))->orderBy('name')->orderBy('id')->get();
    }
}
