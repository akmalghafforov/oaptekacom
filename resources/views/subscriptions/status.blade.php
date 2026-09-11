@extends('layouts.app')
@section('content')
<x-ui.page-header title="Статус аккаунта" description="Доступ аптеки открывается после проверки организации и активации платной подписки." />
<x-ui.card>
    <p class="text-sm text-muted">Текущий тариф</p>
    @if($user->isWholesaler())<p class="mt-1 text-lg font-semibold">Проверка поставщика</p>@else<p class="mt-1 text-lg font-semibold">{{ $user->subscription_plan->label() }}</p>@endif
    @if($subscription?->status === \App\Enums\SubscriptionStatus::Active)
        <p class="mt-2 text-sm text-muted">Подписка действует по {{ $subscription->ends_on->format('d.m.Y') }} включительно.</p>
    @elseif($subscription?->status === \App\Enums\SubscriptionStatus::Pending)
        <x-ui.alert type="warning" class="mt-4">Заявка на оплату ожидает проверки администратора. Доступ к платформе откроется после активации.</x-ui.alert>
    @elseif($user->organization?->status === 'pending')
        <p class="mt-2 text-sm text-muted">Ваша организация ожидает подтверждения администратора.</p>
    @else
        <x-ui.alert type="warning" class="mt-4">Требуется активная платная подписка. Выберите тариф и отправьте заявку на оплату.</x-ui.alert>
    @endif
</x-ui.card>
@if($user->isCustomer() && $user->organization?->status === 'active' && $subscription?->status !== \App\Enums\SubscriptionStatus::Pending)
<x-ui.card class="mt-5">
    <form method="post" action="{{ route('subscription.requests.store') }}" class="grid gap-4 md:grid-cols-2">@csrf
        <x-ui.select name="plan" label="Платный тариф" required><option value="">Выберите тариф</option>@foreach($plans as $plan)<option value="{{ $plan->plan->value }}">{{ $plan->plan->label() }} · {{ $plan->daily_price }} TJS/день</option>@endforeach</x-ui.select>
        <x-ui.select name="term" label="Срок" required><option value="">Выберите срок</option>@foreach($terms as $term)<option value="{{ $term->value }}">{{ $term->label() }}</option>@endforeach</x-ui.select>
        <div class="md:col-span-2"><x-ui.button>Отправить заявку на оплату</x-ui.button></div>
    </form>
</x-ui.card>
@endif
@endsection
