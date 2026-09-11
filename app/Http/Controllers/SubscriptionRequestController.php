<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionTerm;
use App\Http\Requests\CreateSubscriptionRequest;
use App\Models\PaymentMethodSetting;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

class SubscriptionRequestController extends Controller
{
    public function store(CreateSubscriptionRequest $request, SubscriptionService $subscriptions): RedirectResponse
    {
        try {
            $paymentMethod = PaymentMethodSetting::query()->where('method', $request->validated('payment_method'))->first();
            if (! $paymentMethod) {
                throw new InvalidArgumentException('Выбранный способ оплаты сейчас недоступен.');
            }
            $subscriptions->request(
                $request->user(),
                SubscriptionPlan::from($request->validated('plan')),
                SubscriptionTerm::from($request->validated('term')),
                $paymentMethod,
                CarbonImmutable::createFromFormat('d/m/Y', $request->validated('transferred_on'))->toDateString(),
                $request->file('receipt'),
            );
        } catch (InvalidArgumentException $exception) {
            $field = str_contains($exception->getMessage(), 'способ оплаты') ? 'payment_method' : 'plan';

            return back()->withInput()->withErrors([$field => $exception->getMessage()]);
        }

        return redirect()->route('subscription.create')->with('success', 'Заявка на оплату отправлена администратору.');
    }
}
