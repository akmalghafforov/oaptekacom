<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTerm;
use App\Models\PaymentMethodSetting;
use App\Models\SubscriptionPlanPrice;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function create(): View
    {
        $user = auth()->user();

        $terms = [SubscriptionTerm::ThreeMonths, SubscriptionTerm::SixMonths, SubscriptionTerm::Year];
        $plans = SubscriptionPlanPrice::query()->whereIn('plan', [SubscriptionPlan::Base, SubscriptionPlan::Premium])->where('daily_price', '>', 0)->get();
        $today = CarbonImmutable::now(SubscriptionService::TIMEZONE)->startOfDay();

        return view('subscriptions.status', [
            'user' => $user,
            'subscription' => $user->subscriptions()->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Pending, SubscriptionStatus::Rejected])->latest()->first(),
            'plans' => $plans,
            'terms' => $terms,
            'quotes' => $plans->flatMap(fn (SubscriptionPlanPrice $plan) => collect($terms)->map(function (SubscriptionTerm $term) use ($today, $plan): array {
                $endsOn = match ($term) {
                    SubscriptionTerm::ThreeMonths => $today->addMonthsNoOverflow(3)->subDay(),
                    SubscriptionTerm::SixMonths => $today->addMonthsNoOverflow(6)->subDay(),
                    SubscriptionTerm::Year => $today->addYearNoOverflow()->subDay(),
                    default => $today,
                };
                $days = $today->diffInDays($endsOn) + 1;

                return ['plan' => $plan->plan, 'term' => $term, 'days' => $days, 'amount' => bcmul((string) $plan->daily_price, (string) $days, 2)];
            })),
            'paymentMethods' => PaymentMethodSetting::query()->where('is_enabled', true)->whereNotNull('wallet_number')->whereNotNull('instructions')->get(),
        ]);
    }
}
