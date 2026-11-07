<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use App\Models\PromotionQuoteRequest;
use App\Services\AuditLogger;
use App\Services\PromotionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromotionQuoteRequestController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->canBuy() || $user->canSupply() || $user->isAdminMode(), 403);
        $quotes = PromotionQuoteRequest::query()->with('promotion.organization', 'item')
            ->when(! $user->isAdminMode(), function ($query) use ($user): void {
                if ($user->canSupply()) {
                    $query->whereHas('promotion', fn ($query) => $query->where('organization_id', $user->activeOrganizationId()));
                } else {
                    $query->where('user_id', $user->id);
                }
            })->orderByDesc('id')->paginate(30);

        return view('promotions.quotes', compact('quotes'));
    }

    public function store(Promotion $promotion, Request $request, PromotionWorkflow $workflow, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['promotion_item_id' => ['nullable', 'integer'], 'message' => ['required', 'string', 'max:5000']]);
        $quote = $workflow->locked($promotion, function (Promotion $current) use ($request, $data, $audit): PromotionQuoteRequest {
            $this->authorize('create', [PromotionQuoteRequest::class, $current]);
            $item = ($data['promotion_item_id'] ?? null) ? $current->items()->findOrFail($data['promotion_item_id']) : null;
            $user = $request->user();
            $quote = $current->quoteRequests()->create($data + ['user_id' => $user->id, 'buyer_organization_id' => $user->activeOrganizationId(),
                'contact_snapshot' => ['name' => $user->name, 'phone' => $user->phone, 'email' => $user->email,
                    'organization' => $user->activeOrganization()?->name, 'organization_phone' => $user->activeOrganization()?->phone,
                    'promotion_title' => $current->title, 'item_name' => $item?->displayName()]]);
            $audit->log('promotion.quote_requested', $quote, [], $quote->toArray(), $user);

            return $quote;
        });

        return redirect()->route('promotion-quotes.show', $quote)->with('success', 'Запрос сохранён. Компания свяжется с вами вне сайта.');
    }

    public function show(PromotionQuoteRequest $quoteRequest): View
    {
        $this->authorize('view', $quoteRequest);
        $quoteRequest->load('promotion.organization', 'item');

        return view('promotions.quote', compact('quoteRequest'));
    }
}
