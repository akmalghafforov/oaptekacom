<?php

namespace App\Services;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTerm;
use App\Models\AuditEvent;
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
