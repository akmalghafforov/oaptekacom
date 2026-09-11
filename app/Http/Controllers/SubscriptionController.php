<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTerm;
use App\Models\SubscriptionPlanPrice;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function create(): View
    {
        $user = auth()->user();

        return view('subscriptions.status', [
            'user' => $user,
            'subscription' => $user->subscriptions()->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Pending])->latest()->first(),
            'plans' => SubscriptionPlanPrice::query()->whereIn('plan', [SubscriptionPlan::Base, SubscriptionPlan::Premium])->where('daily_price', '>', 0)->get(),
            'terms' => [SubscriptionTerm::ThreeMonths, SubscriptionTerm::SixMonths, SubscriptionTerm::Year],
        ]);
    }
}
