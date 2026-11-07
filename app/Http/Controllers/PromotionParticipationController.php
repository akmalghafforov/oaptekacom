<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePromotionParticipationRequest;
use App\Models\Promotion;
use App\Models\PromotionParticipant;
use App\Services\PromotionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PromotionParticipationController extends Controller
{
    public function update(Promotion $promotion, SavePromotionParticipationRequest $request, PromotionWorkflow $workflow): RedirectResponse
    {
        if ($request->has('add_prize')) {
            return back()->withInput($request->except('add_prize') + ['_prize_rows' => $request->integer('prize_rows') + 1])->withFragment('participation');
        }
        $workflow->saveParticipation($request->user(), $promotion, $request->validated());

        return back()->withFragment('participation')->with('success', 'Условия участия и призы сохранены.');
    }

    public function participants(Promotion $promotion): View
    {
        $this->authorize('manage', $promotion);
        $participants = $promotion->participants()->with('pharmacy')->orderBy('qualified_at')->orderBy('id')->paginate(30);
        $contributions = $promotion->contributions()->with('order')
            ->whereIn('pharmacy_organization_id', $participants->pluck('pharmacy_organization_id'))
            ->orderBy('order_id')->get()->groupBy('pharmacy_organization_id');

        return view('promotions.participants', compact('promotion', 'participants', 'contributions'));
    }

    public function mine(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isPharmacyMode() && $user->canBuy() && $user->hasActiveSubscription(), 403);
        $participants = PromotionParticipant::query()->where('pharmacy_organization_id', $user->activeOrganizationId())
            ->with('promotion.organization')->orderByDesc('qualified_at')->orderByDesc('id')->paginate(30);

        return view('promotions.participations', compact('participants'));
    }
}
