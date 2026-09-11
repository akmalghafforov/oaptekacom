<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionTerm;
use App\Http\Requests\GrantSubscriptionRequest;
use App\Http\Requests\UpdateSubscriptionPlanPricesRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use InvalidArgumentException;

class AdminSubscriptionController extends Controller
{
    public function index(): View
    {
        return view('admin.subscriptions.index', ['users' => User::query()->with('organization')->where('role', 'pharmacy')->orderBy('name')->get(), 'subscriptions' => Subscription::query()->with(['user.organization', 'assignedBy'])->latest()->paginate(30), 'plans' => [SubscriptionPlan::Base, SubscriptionPlan::Premium], 'terms' => SubscriptionTerm::cases()]);
    }

    public function store(GrantSubscriptionRequest $request, SubscriptionService $subscriptions): RedirectResponse
    {
        $data = $request->validated();
        try {
            $subscriptions->grant(User::findOrFail($data['user_id']), $request->user(), SubscriptionPlan::from($data['plan']), SubscriptionTerm::from($data['term']), $data['ends_on'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['plan' => $exception->getMessage()]);
        }

        return redirect()->route('admin.subscriptions.index')->with('success', 'Подписка выдана и сохранена в истории.');
    }

    public function prices(): View
    {
        return view('admin.subscriptions.prices', ['prices' => SubscriptionPlanPrice::query()->get()->keyBy(fn (SubscriptionPlanPrice $price): string => $price->plan->value)]);
    }

    public function updatePrices(UpdateSubscriptionPlanPricesRequest $request): RedirectResponse
    {
        foreach ([[SubscriptionPlan::Base, 'base_daily_price'], [SubscriptionPlan::Premium, 'premium_daily_price']] as [$plan, $field]) {
            $before = SubscriptionPlanPrice::query()->where('plan', $plan->value)->first()?->only('daily_price') ?? [];
            if ($request->filled($field)) {
                $price = SubscriptionPlanPrice::query()->updateOrCreate(['plan' => $plan->value], ['daily_price' => $request->validated($field)]);
                app(AuditLogger::class)->log('subscription.price_updated', $price, $before, $price->only('daily_price'));
            } else {
                SubscriptionPlanPrice::query()->where('plan', $plan->value)->delete();
            }
        }

        return back()->with('success', 'Дневные цены сохранены. Они применятся только к новым подпискам.');
    }
}
