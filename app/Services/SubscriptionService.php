<?php

namespace App\Services;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTerm;
use App\Models\AuditEvent;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SubscriptionService
{
    public const TIMEZONE = 'Asia/Dushanbe';

    public function grant(User $user, User $assignedBy, SubscriptionPlan $plan, SubscriptionTerm $term, ?string $customEndsOn = null): Subscription
    {
        if (! $user->isCustomer() || ! $plan->isPaid()) {
            throw new InvalidArgumentException('Подписку можно выдать только пользователю аптеки на платный тариф.');
        }

        $price = SubscriptionPlanPrice::query()->where('plan', $plan->value)->first();
        if (! $price || bccomp((string) $price->daily_price, '0', 2) !== 1) {
            throw new InvalidArgumentException('Для выбранного тарифа не указана положительная дневная стоимость.');
        }

        [$startsOn, $endsOn] = $this->period($term, $customEndsOn);
        $totalPrice = bcmul((string) $price->daily_price, (string) ($startsOn->diffInDays($endsOn) + 1), 2);

        return DB::transaction(function () use ($user, $assignedBy, $plan, $term, $startsOn, $endsOn, $price, $totalPrice): Subscription {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            Subscription::query()->whereBelongsTo($lockedUser)->where('status', SubscriptionStatus::Active->value)->update(['status' => SubscriptionStatus::Superseded->value, 'actual_ended_at' => now()]);
            $subscription = Subscription::create(['user_id' => $lockedUser->id, 'assigned_by' => $assignedBy->id, 'plan' => $plan, 'term' => $term, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'daily_price' => $price->daily_price, 'total_price' => $totalPrice, 'status' => SubscriptionStatus::Active]);
            $lockedUser->forceFill(['subscription_plan' => $plan])->save();
            AuditEvent::create(['user_id' => $assignedBy->id, 'event' => 'subscription.granted', 'subject_type' => Subscription::class, 'subject_id' => $subscription->id, 'before' => [], 'after' => ['user_id' => $lockedUser->id, 'plan' => $plan->value, 'term' => $term->value, 'starts_on' => $startsOn->toDateString(), 'ends_on' => $endsOn->toDateString(), 'daily_price' => $price->daily_price, 'total_price' => $totalPrice], 'ip' => request()?->ip()]);

            return $subscription;
        });
    }

    public function request(User $user, SubscriptionPlan $plan, SubscriptionTerm $term): Subscription
    {
        if (! $user->isCustomer() || ! $user->organization || $user->organization->status !== 'active' || ! $plan->isPaid() || $term === SubscriptionTerm::Custom) {
            throw new InvalidArgumentException('Заявку может подать только подтверждённая аптека на платный тариф.');
        }

        $price = $this->priceFor($plan);
        [$startsOn, $endsOn] = $this->period($term, null);
        $totalPrice = $this->totalPrice($price, $startsOn, $endsOn);

        return DB::transaction(function () use ($user, $plan, $term, $price, $startsOn, $endsOn, $totalPrice): Subscription {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($lockedUser->subscriptions()->where('status', SubscriptionStatus::Pending->value)->exists()) {
                throw new InvalidArgumentException('У этой аптеки уже есть заявка, ожидающая оплаты или проверки.');
            }

            $paymentRequest = PaymentRequest::create([
                'organization_id' => $lockedUser->organization_id,
                'user_id' => $lockedUser->id,
                'days' => $startsOn->diffInDays($endsOn) + 1,
                'amount' => $totalPrice,
                'status' => 'pending',
            ]);
            $subscription = Subscription::create([
                'user_id' => $lockedUser->id,
                'payment_request_id' => $paymentRequest->id,
                'plan' => $plan,
                'term' => $term,
                'daily_price' => $price->daily_price,
                'total_price' => $totalPrice,
                'status' => SubscriptionStatus::Pending,
            ]);
            AuditEvent::create(['user_id' => $lockedUser->id, 'event' => 'subscription.requested', 'subject_type' => Subscription::class, 'subject_id' => $subscription->id, 'before' => [], 'after' => ['plan' => $plan->value, 'term' => $term->value, 'total_price' => $totalPrice], 'ip' => request()?->ip()]);

            return $subscription;
        });
    }

    public function activate(Subscription $subscription, User $assignedBy): Subscription
    {
        return DB::transaction(function () use ($subscription, $assignedBy): Subscription {
            $lockedSubscription = Subscription::query()->lockForUpdate()->with('user')->findOrFail($subscription->id);
            if ($lockedSubscription->status !== SubscriptionStatus::Pending) {
                throw new InvalidArgumentException('Активировать можно только ожидающую подписку.');
            }
            $lockedUser = User::query()->lockForUpdate()->findOrFail($lockedSubscription->user_id);
            [$startsOn, $endsOn] = $this->period($lockedSubscription->term, null);
            $totalPrice = $this->totalPriceFromDailyPrice((string) $lockedSubscription->daily_price, $startsOn, $endsOn);

            Subscription::query()->whereBelongsTo($lockedUser)->where('status', SubscriptionStatus::Active)->update(['status' => SubscriptionStatus::Superseded, 'actual_ended_at' => now()]);
            $lockedSubscription->update(['assigned_by' => $assignedBy->id, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'total_price' => $totalPrice, 'status' => SubscriptionStatus::Active]);
            $lockedSubscription->paymentRequest?->update(['status' => 'approved', 'reviewed_by' => $assignedBy->id]);
            $lockedUser->forceFill(['subscription_plan' => $lockedSubscription->plan])->save();
            AuditEvent::create(['user_id' => $assignedBy->id, 'event' => 'subscription.activated', 'subject_type' => Subscription::class, 'subject_id' => $lockedSubscription->id, 'before' => ['status' => SubscriptionStatus::Pending->value], 'after' => ['status' => SubscriptionStatus::Active->value], 'ip' => request()?->ip()]);

            return $lockedSubscription;
        });
    }

    public function cancel(Subscription $subscription, User $assignedBy): Subscription
    {
        return DB::transaction(function () use ($subscription, $assignedBy): Subscription {
            $lockedSubscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if (! in_array($lockedSubscription->status, [SubscriptionStatus::Pending, SubscriptionStatus::Active], true)) {
                throw new InvalidArgumentException('Отменить можно только ожидающую или активную подписку.');
            }
            $lockedSubscription->update(['status' => SubscriptionStatus::Cancelled, 'actual_ended_at' => now()]);
            $lockedSubscription->paymentRequest?->update(['status' => 'cancelled', 'reviewed_by' => $assignedBy->id]);
            if ($lockedSubscription->user->subscription_plan === $lockedSubscription->plan) {
                $lockedSubscription->user->update(['subscription_plan' => SubscriptionPlan::Free]);
            }
            AuditEvent::create(['user_id' => $assignedBy->id, 'event' => 'subscription.cancelled', 'subject_type' => Subscription::class, 'subject_id' => $lockedSubscription->id, 'before' => [], 'after' => ['status' => SubscriptionStatus::Cancelled->value], 'ip' => request()?->ip()]);

            return $lockedSubscription;
        });
    }

    public function expireDueSubscriptions(): int
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $count = 0;
        Subscription::query()->where('status', SubscriptionStatus::Active->value)->whereDate('ends_on', '<', $today->toDateString())->orderBy('id')->each(function (Subscription $subscription) use (&$count, $today): void {
            $expired = DB::transaction(function () use ($subscription, $today): bool {
                $locked = Subscription::query()->lockForUpdate()->find($subscription->id);
                if (! $locked || $locked->status !== SubscriptionStatus::Active || ! $locked->ends_on->isBefore($today)) {
                    return false;
                }
                $locked->update(['status' => SubscriptionStatus::Expired, 'actual_ended_at' => now()]);
                $locked->user()->update(['subscription_plan' => SubscriptionPlan::Free]);
                AuditEvent::create(['user_id' => null, 'event' => 'subscription.expired', 'subject_type' => Subscription::class, 'subject_id' => $locked->id, 'before' => ['status' => SubscriptionStatus::Active->value], 'after' => ['status' => SubscriptionStatus::Expired->value], 'ip' => null]);

                return true;
            });
            if ($expired) {
                $count++;
            }
        });

        return $count;
    }

    private function priceFor(SubscriptionPlan $plan): SubscriptionPlanPrice
    {
        $price = SubscriptionPlanPrice::query()->where('plan', $plan->value)->first();
        if (! $price || bccomp((string) $price->daily_price, '0', 2) !== 1) {
            throw new InvalidArgumentException('Для выбранного тарифа не указана положительная дневная стоимость.');
        }

        return $price;
    }

    private function totalPrice(SubscriptionPlanPrice $price, CarbonImmutable $startsOn, CarbonImmutable $endsOn): string
    {
        return $this->totalPriceFromDailyPrice((string) $price->daily_price, $startsOn, $endsOn);
    }

    private function totalPriceFromDailyPrice(string $dailyPrice, CarbonImmutable $startsOn, CarbonImmutable $endsOn): string
    {
        return bcmul($dailyPrice, (string) ($startsOn->diffInDays($endsOn) + 1), 2);
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function period(SubscriptionTerm $term, ?string $customEndsOn): array
    {
        $startsOn = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $endsOn = match ($term) {
            SubscriptionTerm::Year => $startsOn->addYearNoOverflow()->subDay(),
            SubscriptionTerm::SixMonths => $startsOn->addMonthsNoOverflow(6)->subDay(),
            SubscriptionTerm::ThreeMonths => $startsOn->addMonthsNoOverflow(3)->subDay(),
            SubscriptionTerm::Custom => $customEndsOn ? CarbonImmutable::parse($customEndsOn, self::TIMEZONE)->startOfDay() : throw new InvalidArgumentException('Укажите дату окончания произвольной подписки.'),
        };
        if ($endsOn->isBefore($startsOn)) {
            throw new InvalidArgumentException('Дата окончания не может быть в прошлом.');
        }

        return [$startsOn, $endsOn];
    }
}
