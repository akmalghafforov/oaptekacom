@extends('layouts.app')
@section('content')
<x-ui.page-header title="Статус аккаунта" description="Тарифы назначаются администратором после проверки аккаунта." />
<x-ui.card>
    <p class="text-sm text-muted">Текущий тариф</p>
    <p class="mt-1 text-lg font-semibold">{{ $user->subscription_plan->label() }}</p>
    @if($subscription)
        <p class="mt-2 text-sm text-muted">Подписка действует по {{ $subscription->ends_on->format('d.m.Y') }} включительно.</p>
    @elseif($user->organization?->status === 'pending')
        <p class="mt-2 text-sm text-muted">Ваша организация ожидает подтверждения администратора. После одобрения будет доступен бесплатный тариф.</p>
    @else
        <p class="mt-2 text-sm text-muted">Бесплатный тариф доступен. Для Базового или Премиум тарифа обратитесь к администратору.</p>
    @endif
</x-ui.card>
@endsection
