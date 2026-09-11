<?php

namespace App\Services;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTerm;
use App\Models\AuditEvent;
use App\Models\PaymentMethodSetting;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPrice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
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

    public function request(User $user, SubscriptionPlan $plan, SubscriptionTerm $term, PaymentMethodSetting $paymentMethod, string $transferReference, string $transferredOn, UploadedFile $receipt): Subscription
    {
        if (! $user->isCustomer() || ! $user->organization || $user->organization->status !== 'active' || ! $plan->isPaid() || $term === SubscriptionTerm::Custom) {
            throw new InvalidArgumentException('Заявку может подать только подтверждённая аптека на платный тариф.');
        }

        if (! $paymentMethod->isConfigured()) {
            throw new InvalidArgumentException('Выбранный способ оплаты сейчас недоступен.');
        }

        $price = $this->priceFor($plan);
        [$startsOn, $endsOn] = $this->period($term, null);
        $totalPrice = $this->totalPrice($price, $startsOn, $endsOn);

        return DB::transaction(function () use ($user, $plan, $term, $paymentMethod, $price, $startsOn, $endsOn, $totalPrice, $transferReference, $transferredOn, $receipt): Subscription {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($lockedUser->subscriptions()->where('status', SubscriptionStatus::Pending->value)->exists()) {
                throw new InvalidArgumentException('У этой аптеки уже есть заявка, ожидающая оплаты или проверки.');
            }

            $receiptPath = $receipt->store('payment-receipts', 'local');
            $paymentRequest = PaymentRequest::create([
                'organization_id' => $lockedUser->organization_id,
                'user_id' => $lockedUser->id,
                'days' => $startsOn->diffInDays($endsOn) + 1,
                'amount' => $totalPrice,
                'payment_method' => $paymentMethod->method,
                'recipient_wallet' => $paymentMethod->wallet_number,
                'payment_instructions' => $paymentMethod->instructions,
                'transfer_reference' => $transferReference,
                'transferred_on' => $transferredOn,
                'receipt_path' => $receiptPath,
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
            AuditEvent::create(['user_id' => $lockedUser->id, 'event' => 'subscription.requested', 'subject_type' => Subscription::class, 'subject_id' => $subscription->id, 'before' => [], 'after' => ['plan' => $plan->value, 'term' => $term->value, 'days' => $paymentRequest->days, 'total_price' => $totalPrice, 'payment_method' => $paymentMethod->method->value], 'ip' => request()?->ip()]);

            return $subscription;
        });
    }

    public function approvePayment(PaymentRequest $paymentRequest, User $reviewer, string $verifiedAmount, string $verifiedReference): Subscription
    {
        return DB::transaction(function () use ($paymentRequest, $reviewer, $verifiedAmount, $verifiedReference): Subscription {
            $lockedRequest = PaymentRequest::query()->lockForUpdate()->findOrFail($paymentRequest->id);
            if ($lockedRequest->status !== 'pending') {
                throw new InvalidArgumentException('Проверить можно только заявку, ожидающую проверки.');
            }
            if (bccomp($verifiedAmount, (string) $lockedRequest->amount, 2) !== 0) {
                throw new InvalidArgumentException('Подтверждённая сумма должна точно совпадать с суммой заявки.');
            }
            $lockedSubscription = Subscription::query()->lockForUpdate()->where('payment_request_id', $lockedRequest->id)->firstOrFail();
            $lockedUser = User::query()->lockForUpdate()->findOrFail($lockedSubscription->user_id);
            $startsOn = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
            $endsOn = $startsOn->addDays($lockedRequest->days - 1);

            Subscription::query()->whereBelongsTo($lockedUser)->where('status', SubscriptionStatus::Active)->update(['status' => SubscriptionStatus::Superseded, 'actual_ended_at' => now()]);
            $lockedSubscription->update(['assigned_by' => $reviewer->id, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'status' => SubscriptionStatus::Active]);
            $lockedRequest->update(['status' => 'approved', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'verified_amount' => $verifiedAmount, 'verified_reference' => $verifiedReference]);
            $lockedUser->forceFill(['subscription_plan' => $lockedSubscription->plan])->save();
            AuditEvent::create(['user_id' => $reviewer->id, 'event' => 'payment_request.approved', 'subject_type' => PaymentRequest::class, 'subject_id' => $lockedRequest->id, 'before' => ['status' => 'pending'], 'after' => ['status' => 'approved', 'verified_amount' => $verifiedAmount, 'verified_reference' => $verifiedReference], 'ip' => request()?->ip()]);
            AuditEvent::create(['user_id' => $reviewer->id, 'event' => 'subscription.activated', 'subject_type' => Subscription::class, 'subject_id' => $lockedSubscription->id, 'before' => ['status' => SubscriptionStatus::Pending->value], 'after' => ['status' => SubscriptionStatus::Active->value], 'ip' => request()?->ip()]);

            return $lockedSubscription;
        });
    }

    public function rejectPayment(PaymentRequest $paymentRequest, User $reviewer, string $reason): void
    {
        DB::transaction(function () use ($paymentRequest, $reviewer, $reason): void {
            $lockedRequest = PaymentRequest::query()->lockForUpdate()->findOrFail($paymentRequest->id);
            if ($lockedRequest->status !== 'pending') {
                throw new InvalidArgumentException('Проверить можно только заявку, ожидающую проверки.');
            }
            $subscription = Subscription::query()->lockForUpdate()->where('payment_request_id', $lockedRequest->id)->firstOrFail();
            $lockedRequest->update(['status' => 'rejected', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'rejection_reason' => $reason]);
            $subscription->update(['status' => SubscriptionStatus::Rejected]);
            AuditEvent::create(['user_id' => $reviewer->id, 'event' => 'payment_request.rejected', 'subject_type' => PaymentRequest::class, 'subject_id' => $lockedRequest->id, 'before' => ['status' => 'pending'], 'after' => ['status' => 'rejected', 'rejection_reason' => $reason], 'ip' => request()?->ip()]);
        });
    }

    public function cancel(Subscription $subscription, User $assignedBy): Subscription
    {
        return DB::transaction(function () use ($subscription, $assignedBy): Subscription {
            $lockedSubscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if ($lockedSubscription->status !== SubscriptionStatus::Active) {
                throw new InvalidArgumentException('Отменить можно только активную подписку.');
            }
            $lockedSubscription->update(['status' => SubscriptionStatus::Cancelled, 'actual_ended_at' => now()]);
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
