@extends('layouts.app')
@section('content')
<x-ui.page-header :title="$organization->name" description="Рабочий кабинет организации" />
<x-ui.card class="mb-6"><p class="text-sm text-muted">Ваш тариф</p><p class="mt-1 text-lg font-semibold">{{ auth()->user()->subscription_plan->label() }}</p>@if($subscription)<p class="mt-1 text-sm text-muted">Действует по {{ $subscription->ends_on->format('d.m.Y') }} включительно.</p>@endif</x-ui.card>
<h2 class="mb-3 text-lg font-bold">Последние заказы</h2><div class="space-y-3">@forelse($orders as $order)<x-ui.card class="flex flex-wrap items-center justify-between gap-3"><div><a class="font-semibold text-brand-700 hover:underline" href="{{ route('orders.show', $order) }}">Заказ №{{ $order->id }}</a><p class="mt-1 text-sm text-muted">{{ $order->total }} TJS</p></div><x-ui.status-badge :status="$order->status" /></x-ui.card>@empty<x-ui.empty-state title="Заказов пока нет" description="Найдите нужные товары в каталоге и добавьте их в корзину."><x-slot:action><a class="font-semibold text-brand-700 hover:underline" href="{{ route('catalog') }}">Открыть каталог</a></x-slot:action></x-ui.empty-state>@endforelse</div>
@endsection
