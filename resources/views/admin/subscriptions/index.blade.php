@extends('layouts.app')

@section('content')
<x-ui.page-header title="Подписки пользователей" description="Контроль сроков и история подписок аккаунтов аптек.">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('admin.subscriptions.create')">Выдать подписку</x-ui.button>
            <x-ui.button variant="secondary" :href="route('admin.subscription-prices.edit')">Цены тарифов</x-ui.button>
            <x-ui.button variant="secondary" :href="route('admin.subscription-payments.index')">Платежи</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.pharmacies.index')">Аптеки</x-ui.button>
            <x-ui.button variant="ghost" :href="route('admin.providers.index')">Поставщики</x-ui.button>
        </div>
    </x-slot:actions>
</x-ui.page-header>

<section class="mb-6" aria-labelledby="subscription-totals">
    <h2 id="subscription-totals" class="text-lg font-semibold text-heading">Общие итоги по всем подпискам</h2>
    <p class="mt-1 text-sm text-muted">Количество записей, независимо от фильтров. Даты — Душанбе: {{ $today->format('d.m.Y') }}. Периоды 7 и 30 дней включают сегодня и пересекаются.</p>
    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['key' => 'active', 'label' => 'Активные', 'query' => ['status' => 'active'], 'color' => 'text-success'],
            ['key' => 'expired', 'label' => 'Истекшие', 'query' => ['status' => 'expired'], 'color' => 'text-muted'],
            ['key' => '7', 'label' => 'Истекают в течение 7 дней', 'query' => ['expires_in' => '7'], 'color' => 'text-warning'],
            ['key' => '30', 'label' => 'Истекают в течение 30 дней', 'query' => ['expires_in' => '30'], 'color' => 'text-warning'],
        ] as $statistic)
            <a href="{{ route('admin.subscriptions.index', $statistic['query']) }}" class="block rounded-panel focus-visible:outline-focus" aria-label="{{ $statistic['label'] }}: {{ $statistics[$statistic['key']] }}. Показать подписки">
                <x-ui.card class="h-full hover:bg-surface-subtle">
                    <span class="block text-sm font-medium text-secondary">{{ $statistic['label'] }}</span>
                    <span class="mt-2 block text-3xl font-bold tabular-nums {{ $statistic['color'] }}">{{ $statistics[$statistic['key']] }}</span>
                </x-ui.card>
            </a>
        @endforeach
    </div>
</section>

<x-ui.card class="mb-6">
    <form method="get" action="{{ route('admin.subscriptions.index') }}" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5" data-subscription-filters>
        @if($sort)
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
        @endif
        <x-ui.input name="phone" label="Телефон аккаунта" :value="$filters['phone'] ?? null" placeholder="+992 90 123 45 67" hint="Можно ввести часть номера." />
        <x-ui.select name="status" label="Статус">
            <option value="">Все статусы</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}" @selected(old('status', $filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.input name="ends_from" type="date" label="Окончание с" :value="$filters['ends_from'] ?? null" />
        <x-ui.input name="ends_to" type="date" label="Окончание по" :value="$filters['ends_to'] ?? null" />
        <x-ui.select name="expires_in" label="Скоро истекают">
            <option value="">Любой срок</option>
            <option value="7" @selected(old('expires_in', $filters['expires_in'] ?? '') === '7')>В течение 7 дней</option>
            <option value="30" @selected(old('expires_in', $filters['expires_in'] ?? '') === '30')>В течение 30 дней</option>
        </x-ui.select>
        <div class="flex flex-wrap gap-2 sm:col-span-2 xl:col-span-5">
            <x-ui.button>Применить</x-ui.button>
            <x-ui.button variant="secondary" :href="route('admin.subscriptions.index')">Сбросить</x-ui.button>
        </div>
    </form>
</x-ui.card>

<div class="mb-3 flex flex-wrap justify-between gap-2 text-sm text-muted">
    <p>Найдено по фильтрам: <strong class="text-heading">{{ $subscriptions->total() }}</strong></p>
    <p>Сортировка: {{ $sort ? $sortLabels[$sort].' · '.($direction === 'asc' ? 'по возрастанию ↑' : 'по убыванию ↓') : 'сначала новые (по умолчанию)' }}.</p>
</div>
@php
    $pageAccounts = $subscriptions->pluck('user')->filter(fn ($user) => $user?->isCustomer())->unique('id');
    $selectedAccountIds = $pageAccounts->pluck('id')->intersect((array) old('user_ids', []))->values()->all();
@endphp
<div data-bulk-subscriptions>
<div class="mb-3 flex flex-wrap items-center gap-3">
    <x-ui.button type="button" variant="secondary" data-bulk-open data-dialog-open aria-controls="bulk-subscriptions-dialog" aria-haspopup="dialog" :disabled="count($selectedAccountIds) === 0">Массовые действия</x-ui.button>
    <p class="text-sm text-muted" aria-live="polite">Выбрано аккаунтов: <span data-selected-count>{{ count($selectedAccountIds) }}</span></p>
</div>
<div class="table-wrap" role="region" aria-label="Подписки пользователей" tabindex="0">
    <table class="data-table">
        <thead>
            <tr>
                <th scope="col"><x-ui.checkbox name="select_page" data-select-page :disabled="$pageAccounts->isEmpty()"><span class="sr-only">Выбрать все аккаунты на этой странице</span></x-ui.checkbox></th>
                @foreach(['pharmacy' => 'Аптека', 'account' => 'Аккаунт', 'phone' => 'Телефон аккаунта', 'plan' => 'Тариф', 'term' => 'Срок', 'starts_on' => 'Начало', 'ends_on' => 'Окончание', 'total_price' => 'Стоимость', 'status' => 'Статус'] as $column => $label)
                    @if($column === 'term')
                        <th scope="col">{{ $label }}</th>
                    @else
                        @php($nextDirection = $sort === $column && $direction === 'asc' ? 'desc' : 'asc')
                        <th scope="col" aria-sort="{{ $sort === $column ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                            <x-ui.button variant="ghost" :href="route('admin.subscriptions.index', array_merge($filters, ['sort' => $column, 'direction' => $nextDirection]))" class="whitespace-nowrap" :aria-label="$label.'. Сортировать по '.($nextDirection === 'asc' ? 'возрастанию' : 'убыванию')">
                                {{ $label }} <span aria-hidden="true" class="ml-1">{{ $sort === $column ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                            </x-ui.button>
                        </th>
                    @endif
                @endforeach
                <th scope="col" class="text-right">Действие</th>
            </tr>
        </thead>
        <tbody>
            @forelse($subscriptions as $subscription)
                @php($effectiveStatus = $subscription->effectiveStatusOn($today))
                <tr>
                    <td>
                        @if($subscription->user?->isCustomer())
                            <x-ui.checkbox :name="'subscription-selection-'.$subscription->id" :value="$subscription->user_id" :checked="in_array($subscription->user_id, $selectedAccountIds)" data-account-checkbox>
                                <span class="sr-only">Выбрать аккаунт: {{ $subscription->user->name }} · {{ $subscription->user->phone }}</span>
                            </x-ui.checkbox>
                        @endif
                    </td>
                    <td>{{ $subscription->user?->organization?->name ?: '—' }}</td>
                    <td>{{ $subscription->user?->name ?: '—' }}</td>
                    <td class="whitespace-nowrap">{{ $subscription->user?->phone ?: '—' }}</td>
                    <td>{{ $subscription->plan?->label() ?? '—' }}</td>
                    <td>{{ $subscription->term?->label() ?? '—' }}</td>
                    <td class="whitespace-nowrap">{{ $subscription->starts_on?->format('d.m.Y') ?? '—' }}</td>
                    <td class="whitespace-nowrap">
                        {{ $subscription->ends_on?->format('d.m.Y') ?? '—' }}
                        @if($effectiveStatus === \App\Enums\SubscriptionStatus::Pending)
                            <span class="block text-xs text-muted">После проверки оплаты</span>
                        @elseif($effectiveStatus === \App\Enums\SubscriptionStatus::Active && $subscription->ends_on)
                            @if($subscription->ends_on->toDateString() === $today->toDateString())
                                <span class="block text-xs font-semibold text-warning">Истекает сегодня</span>
                            @elseif($subscription->ends_on->toDateString() <= $today->copy()->addDays(7)->toDateString())
                                <span class="block text-xs font-semibold text-warning">Истекает в течение 7 дней</span>
                            @elseif($subscription->ends_on->toDateString() <= $today->copy()->addDays(30)->toDateString())
                                <span class="block text-xs text-warning">Истекает в течение 30 дней</span>
                            @endif
                        @endif
                    </td>
                    <td class="whitespace-nowrap">{{ $subscription->total_price !== null ? $subscription->total_price.' TJS' : '—' }}<span class="block text-xs text-muted">{{ $subscription->daily_price !== null ? $subscription->daily_price.' TJS/день' : '—' }}</span></td>
                    <td @class(['font-medium', 'text-success' => $effectiveStatus === \App\Enums\SubscriptionStatus::Active, 'text-warning' => $effectiveStatus === \App\Enums\SubscriptionStatus::Pending, 'text-muted' => $effectiveStatus === \App\Enums\SubscriptionStatus::Expired])>{{ $effectiveStatus->label() }}</td>
                    <td class="text-right">
                        @if($subscription->status === \App\Enums\SubscriptionStatus::Pending)
                            <x-ui.button variant="secondary" :href="route('admin.subscription-payments.index')">Проверить оплату</x-ui.button>
                        @endif
                        @if($subscription->status === \App\Enums\SubscriptionStatus::Active)
                            <form method="post" action="{{ route('admin.subscriptions.cancel', $subscription) }}">
                                @csrf
                                <x-mode-version />
                                <x-ui.button variant="danger">Отменить</x-ui.button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="11"><x-ui.empty-state title="Подписки не найдены" description="Измените фильтры или выдайте подписку аккаунту аптеки." /></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-6">{{ $subscriptions->links('components.ui.pagination') }}</div>
<x-ui.dialog name="bulk-subscriptions-dialog" title="Массовые действия" description="Изменения применятся ко всем выбранным аккаунтам вместе." :open-on-load="$errors->any() && (old('_bulk_subscriptions') || old('user_ids') !== null)">
    <form id="bulk-subscriptions-form" method="post" action="{{ route('admin.subscriptions.bulk.store') }}" class="overflow-y-auto p-5" data-bulk-form>
        @csrf
        <x-mode-version />
        <input type="hidden" name="_bulk_subscriptions" value="1">
        @foreach($pageAccounts as $account)
            <input type="hidden" name="user_ids[]" value="{{ $account->id }}" data-selected-account @disabled(! in_array($account->id, $selectedAccountIds))>
        @endforeach
        @if($errors->any() && (old('_bulk_subscriptions') || old('user_ids') !== null))
            <x-ui.alert type="danger" class="mb-4">
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-ui.alert>
        @endif
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.select name="action" label="Действие" required hint="Тариф начинает действовать сегодня. Продление сохраняет оставшееся время.">
                <option value="set" @selected(old('action', 'set') === 'set')>Назначить — заменить текущее покрытие</option>
                <option value="extend" @selected(old('action') === 'extend')>Продлить — сохранить оставшееся время</option>
            </x-ui.select>
            <x-ui.select name="plan" label="Платный тариф" required>
                <option value="">Выберите тариф</option>
                @foreach($plans as $plan)
                    <option value="{{ $plan->value }}" @selected(old('plan') === $plan->value)>{{ $plan->label() }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="term" label="Срок" required>
                <option value="">Выберите срок</option>
                @foreach($terms as $term)
                    <option value="{{ $term->value }}" @selected(old('term') === $term->value)>{{ $term->label() }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input name="ends_on" type="date" label="Дата окончания" hint="Для произвольного срока: абсолютная дата включительно. При продлении должна быть позже текущего окончания каждого выбранного аккаунта." min="{{ now('Asia/Dushanbe')->toDateString() }}" />
            <p class="text-sm text-muted sm:col-span-2">Сроки считаются по календарю Душанбе. Стоимость новой записи — все дни с сегодняшнего дня по дату окончания включительно по текущей дневной цене. Ожидающие заявки на оплату сохраняются.</p>
        </div>
        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <x-ui.button type="button" variant="secondary" data-dialog-close>Отмена</x-ui.button>
            <x-ui.button data-bulk-apply :disabled="count($selectedAccountIds) === 0">Применить</x-ui.button>
        </div>
    </form>
</x-ui.dialog>
</div>
@endsection
