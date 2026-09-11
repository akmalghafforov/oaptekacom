@extends('layouts.app')
@section('content')
<x-ui.page-header title="Цены тарифов" description="Цена указывается в сомони за календарный день. Пустое поле делает тариф недоступным для новых выдач."><x-slot:actions><a class="text-sm font-semibold text-brand-700 hover:underline" href="{{ route('admin.subscriptions.index') }}">Подписки</a></x-slot:actions></x-ui.page-header>
<x-ui.card><form method="post" action="{{ route('admin.subscription-prices.update') }}" class="grid gap-5 md:grid-cols-2">@csrf @method('PATCH')
<x-ui.input name="base_daily_price" type="number" step="0.01" min="0" label="Базовый тариф, TJS/день" :value="$prices['base']->daily_price ?? null" hint="Укажите положительную сумму для доступности тарифа." />
<x-ui.input name="premium_daily_price" type="number" step="0.01" min="0" label="Премиум тариф, TJS/день" :value="$prices['premium']->daily_price ?? null" hint="Укажите положительную сумму для доступности тарифа." />
<div class="md:col-span-2"><x-ui.button>Сохранить цены</x-ui.button></div></form></x-ui.card>
@endsection
