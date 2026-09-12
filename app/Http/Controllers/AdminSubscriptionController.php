<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionTerm;
use App\Http\Requests\GrantSubscriptionRequest;
use App\Http\Requests\ReviewPaymentRequest;
use App\Http\Requests\UpdatePaymentMethodsRequest;
use App\Http\Requests\UpdateSubscriptionPlanPricesRequest;
use App\Models\PaymentMethodSetting;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function paymentRequests(): View
    {
        return view('admin.subscriptions.payments', ['paymentRequests' => PaymentRequest::query()->with(['organization', 'user', 'reviewer'])->latest()->paginate(30), 'methods' => PaymentMethodSetting::query()->get()->keyBy(fn (PaymentMethodSetting $method): string => $method->method->value), 'paymentMethodCases' => PaymentMethod::cases()]);
    }

    public function reviewPayment(PaymentRequest $paymentRequest, ReviewPaymentRequest $request, SubscriptionService $subscriptions): RedirectResponse
    {
        $data = $request->validated();
        try {
            if ($data['decision'] === 'approve') {
                $subscriptions->approvePayment($paymentRequest, $request->user(), $data['verified_amount'], $data['verified_reference']);

                return back()->with('success', 'Оплата подтверждена, подписка активирована.');
            }
            $subscriptions->rejectPayment($paymentRequest, $request->user(), $data['rejection_reason']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['payment_request' => $exception->getMessage()]);
        }

        return back()->with('success', 'Заявка на оплату отклонена.');
    }

    public function updatePaymentMethods(UpdatePaymentMethodsRequest $request, AuditLogger $audit): RedirectResponse
    {
        foreach ($request->validated('methods') as $method) {
            $isEnabled = (bool) ($method['is_enabled'] ?? false);
            if ($isEnabled && (! filled($method['wallet_number'] ?? null) || ! filled($method['wallet_owner_name'] ?? null))) {
                return back()->withInput()->withErrors(['methods' => 'Для включённого способа укажите номер таджикского кошелька и имя владельца.']);
            }
            $setting = PaymentMethodSetting::query()->firstOrNew(['method' => $method['method']]);
            $before = $setting->exists ? $setting->only(['is_enabled', 'wallet_number', 'wallet_owner_name']) : [];
            $setting->fill(['is_enabled' => $isEnabled, 'wallet_number' => $method['wallet_number'] ?? null, 'wallet_owner_name' => $method['wallet_owner_name'] ?? null])->save();
            $audit->log('payment_method.updated', $setting, $before, $setting->only(['is_enabled', 'wallet_number', 'wallet_owner_name']));
        }

        return back()->with('success', 'Способы оплаты сохранены.');
    }

    public function cancel(Subscription $subscription, SubscriptionService $subscriptions, Request $request): RedirectResponse
    {
        try {
            $subscriptions->cancel($subscription, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['subscription' => $exception->getMessage()]);
        }

        return back()->with('success', 'Подписка отменена.');
    }

    public function prices(): View
    {
        return view('admin.subscriptions.prices', ['prices' => SubscriptionPlanPrice::query()->get()->keyBy(fn (SubscriptionPlanPrice $price): string => $price->plan->value)]);
    }

    public function updatePrices(UpdateSubscriptionPlanPricesRequest $request, AuditLogger $auditLogger): RedirectResponse
    {
        foreach ([[SubscriptionPlan::Base, 'base_daily_price'], [SubscriptionPlan::Premium, 'premium_daily_price']] as [$plan, $field]) {
            $before = SubscriptionPlanPrice::query()->where('plan', $plan->value)->first()?->only('daily_price') ?? [];
            if ($request->filled($field)) {
                $price = SubscriptionPlanPrice::query()->updateOrCreate(['plan' => $plan->value], ['daily_price' => $request->validated($field)]);
                $auditLogger->log('subscription.price_updated', $price, $before, $price->only('daily_price'));
            } else {
                SubscriptionPlanPrice::query()->where('plan', $plan->value)->delete();
            }
        }

        return back()->with('success', 'Дневные цены сохранены. Они применятся только к новым подпискам.');
    }
}
