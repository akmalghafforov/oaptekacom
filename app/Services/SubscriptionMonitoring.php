<?php

namespace App\Services;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionMonitoring
{
    public const array SORT_LABELS = [
        'pharmacy' => 'Аптека',
        'account' => 'Аккаунт',
        'phone' => 'Телефон аккаунта',
        'plan' => 'Тариф',
        'status' => 'Статус',
        'starts_on' => 'Начало',
        'ends_on' => 'Окончание',
        'total_price' => 'Стоимость',
    ];

    /**
     * @param  array{phone?: ?string, status?: ?string, ends_from?: ?string, ends_to?: ?string, expires_in?: ?string, sort?: ?string, direction?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function data(array $filters): array
    {
        $today = now('Asia/Dushanbe')->startOfDay();
        $current = Subscription::query()->currentPaid($today);
        $currentAccountIds = (clone $current)->select('subscriptions.user_id');
        $statistics = [
            'active' => (clone $current)->count(),
            'expired' => Subscription::query()->effectiveStatus(SubscriptionStatus::Expired, $today)
                ->whereNotIn('subscriptions.user_id', $currentAccountIds)->count(),
            '7' => $this->expiringWithin(Subscription::query(), $today, 7)->count(),
            '30' => $this->expiringWithin(Subscription::query(), $today, 30)->count(),
        ];

        $query = Subscription::query()->with('user.organization')
            ->leftJoin('users', 'users.id', '=', 'subscriptions.user_id')
            ->leftJoin('organizations', 'organizations.id', '=', 'users.organization_id')
            ->select('subscriptions.*');

        if (filled($filters['phone'] ?? null)) {
            $phone = preg_replace('/[^0-9]/', '', $filters['phone']);
            $query->whereRaw($this->normalizedPhoneSql().' LIKE ?', ['%'.$this->withoutCountryPrefix($phone, $filters['phone']).'%']);
        }
        if (filled($filters['status'] ?? null)) {
            $query->effectiveStatus(SubscriptionStatus::from($filters['status']), $today);
            if ($filters['status'] === SubscriptionStatus::Expired->value) {
                $query->whereNotIn('subscriptions.user_id', $currentAccountIds);
            }
        }
        if (filled($filters['ends_from'] ?? null)) {
            $query->whereDate('subscriptions.ends_on', '>=', $filters['ends_from']);
        }
        if (filled($filters['ends_to'] ?? null)) {
            $query->whereDate('subscriptions.ends_on', '<=', $filters['ends_to']);
        }
        if (filled($filters['expires_in'] ?? null)) {
            $this->expiringWithin($query, $today, (int) $filters['expires_in']);
        }

        $sort = $filters['sort'] ?? null;
        $direction = $filters['direction'] ?? 'asc';
        $this->sort($query, $sort, $direction, $today);

        return [
            'subscriptions' => $query->paginate(30)->appends(array_filter($filters, fn (mixed $value): bool => filled($value))),
            'statistics' => $statistics,
            'today' => $today,
            'statuses' => SubscriptionStatus::cases(),
            'sortLabels' => self::SORT_LABELS,
            'sort' => $sort,
            'direction' => $direction,
            'filters' => $filters,
        ];
    }

    private function expiringWithin(Builder $query, CarbonInterface $today, int $days): Builder
    {
        $endsOn = $today->copy()->addDays($days)->toDateString();

        return $query->currentPaid($today)
            ->whereDate('subscriptions.ends_on', '<=', $endsOn)
            ->whereNotIn('subscriptions.user_id', Subscription::query()->currentPaid($today)
                ->whereDate('subscriptions.ends_on', '>', $endsOn)->select('subscriptions.user_id'));
    }

    private function withoutCountryPrefix(string $phone, string $search): string
    {
        if (str_starts_with($phone, '00992')) {
            return substr($phone, 5);
        }
        if (str_starts_with($phone, '992') && ((strlen($phone) > 3 && strlen($phone) !== 9) || preg_match('/^\s*\+/', $search))) {
            return substr($phone, 3);
        }

        return $phone;
    }

    private function normalizedPhoneSql(): string
    {
        $phone = "COALESCE(users.phone, '')";
        foreach ([' ', "\t", "\n", "\r", "\u{00a0}", "\u{202f}", '(', ')', '-', '‑', '–', '—', '.', '+'] as $separator) {
            $phone = "REPLACE($phone, '$separator', '')";
        }

        return "NULLIF(CASE WHEN LENGTH($phone) > 9 AND SUBSTR($phone, 1, 5) = '00992' THEN SUBSTR($phone, 6) WHEN LENGTH($phone) > 9 AND SUBSTR($phone, 1, 3) = '992' THEN SUBSTR($phone, 4) ELSE $phone END, '')";
    }

    private function sort(Builder $query, ?string $sort, string $direction, CarbonInterface $today): void
    {
        if ($sort === null) {
            $query->orderByDesc('subscriptions.created_at')->orderByDesc('subscriptions.id');

            return;
        }

        $bindings = [];
        $expression = match ($sort) {
            'pharmacy' => "NULLIF(organizations.name, '')",
            'account' => "NULLIF(users.name, '')",
            'phone' => $this->normalizedPhoneSql(),
            'plan' => $this->enumLabelSql('subscriptions.plan', SubscriptionPlan::cases(), $bindings),
            'status' => $this->enumLabelSql('CASE WHEN subscriptions.status = ? AND DATE(subscriptions.ends_on) < ? THEN ? ELSE subscriptions.status END', SubscriptionStatus::cases(), $bindings),
            default => 'subscriptions.'.$sort,
        };
        if ($sort === 'status') {
            array_unshift($bindings, SubscriptionStatus::Active->value, $today->toDateString(), SubscriptionStatus::Expired->value);
        }

        $query->orderByRaw("($expression) IS NULL ASC", $bindings)
            ->orderByRaw("$expression $direction", $bindings)
            ->orderByDesc('subscriptions.id');
    }

    /**
     * @param  array<SubscriptionPlan|SubscriptionStatus>  $cases
     * @param  list<string>  $bindings
     */
    private function enumLabelSql(string $column, array $cases, array &$bindings): string
    {
        $expression = 'CASE '.$column;
        foreach ($cases as $case) {
            $expression .= ' WHEN ? THEN ?';
            $bindings[] = $case->value;
            $bindings[] = $case->label();
        }

        return $expression.' END';
    }
}
