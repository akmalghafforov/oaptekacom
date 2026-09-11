<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentMethodSetting;
use App\Models\SubscriptionPlanPrice;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function create(): View
    {
        $user = auth()->user();

        $plans = SubscriptionPlanPrice::query()->whereIn('plan', [SubscriptionPlan::Base, SubscriptionPlan::Premium])->where('daily_price', '>', 0)->get();

        return view('subscriptions.status', [
            'user' => $user,
            'subscription' => $user->subscriptions()->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Pending, SubscriptionStatus::Rejected])->latest()->first(),
            'plans' => $plans,
            'paymentMethods' => PaymentMethodSetting::query()->where('is_enabled', true)->whereNotNull('wallet_number')->whereNotNull('wallet_owner_name')->get(),
        ]);
    }
}
