<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionTerm;
use App\Http\Requests\CreateSubscriptionRequest;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

class SubscriptionRequestController extends Controller
{
    public function store(CreateSubscriptionRequest $request, SubscriptionService $subscriptions): RedirectResponse
    {
        try {
            $subscriptions->request(
                $request->user(),
                SubscriptionPlan::from($request->validated('plan')),
                SubscriptionTerm::from($request->validated('term')),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['plan' => $exception->getMessage()]);
        }

        return redirect()->route('subscription.create')->with('success', 'Заявка на оплату отправлена администратору.');
    }
}
