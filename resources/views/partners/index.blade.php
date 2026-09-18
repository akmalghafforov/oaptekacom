@extends('layouts.app')

@section('content')
<x-ui.page-header title="Партнёры" description="Контакты поставщиков и согласованные условия для вашей аптеки." />

<div class="grid gap-4 lg:grid-cols-3">
    <x-ui.card class="lg:col-span-2">
        <form method="get" action="{{ route('partners.index') }}" class="grid gap-3 sm:grid-cols-[1fr_13rem_auto] sm:items-end">
            <x-ui.input name="name" label="Название поставщика" :value="request('name')" placeholder="Поиск по названию" />
            <x-ui.select name="city" label="Город"><option value="">Все города</option>@foreach($cities as $city)<option value="{{ $city }}" @selected(request('city') === $city)>{{ $city }}</option>@endforeach</x-ui.select>
            <x-ui.button>Найти</x-ui.button>
        </form>
    </x-ui.card>
    <x-ui.card>
        <h2 class="font-bold">Добавить поставщика</h2>
        <form method="post" action="{{ route('partners.phone') }}" class="mt-3 space-y-3">@csrf <x-ui.input name="phone" label="Телефон организации" placeholder="+992901234567" required /><x-ui.button class="w-full">По телефону</x-ui.button></form>
        <form method="post" action="{{ route('partners.code') }}" class="mt-4 space-y-3 border-t border-slate-200 pt-4">@csrf <x-ui.input name="code" label="Код приглашения" required /><x-ui.button variant="secondary" class="w-full">По коду</x-ui.button></form>
    </x-ui.card>
</div>

<div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
@forelse($suppliers as $supplier)
    @php
        $link = $links->get($supplier->id);
        $import = $supplier->priceListImports->first();
        $email = $supplier->contact_email ?: $supplier->senderAddresses->first()?->email;
        $phones = array_values(array_filter(array_merge([$supplier->phone], $supplier->additional_phones ?? [])));
    @endphp
    <x-ui.card class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2"><div><h2 class="text-lg font-bold text-ink">{{ $supplier->name }}</h2><p class="text-sm text-muted">{{ $supplier->city ?: 'Город не указан' }}</p></div>@if($link)<x-ui.status-badge status="completed" label="Партнёр" />@endif</div>
        <dl class="grid gap-2 text-sm">
            <div><dt class="text-muted">Контактное лицо</dt><dd class="font-medium">{{ $supplier->contact_name ?: '—' }}</dd></div>
            <div><dt class="text-muted">Телефоны</dt><dd class="font-medium">{{ $phones ? implode(', ', $phones) : '—' }}</dd></div>
            <div><dt class="text-muted">WhatsApp</dt><dd class="font-medium">{{ $supplier->whatsapp_phone ?: '—' }}</dd></div>
            <div><dt class="text-muted">Email</dt><dd class="font-medium">{{ $email ?: '—' }}</dd></div>
            <div><dt class="text-muted">Последний прайс-лист</dt><dd class="font-medium">{{ $import ? ($import->received_at ?? $import->created_at)->timezone('Asia/Dushanbe')->format('d.m.Y H:i') : '—' }}</dd></div>
            <div><dt class="text-muted">Статус импорта</dt><dd>@if($import)<x-ui.status-badge :status="$import->status->value" :label="$import->status->label()" />@else — @endif</dd></div>
            <div><dt class="text-muted">Согласованная скидка</dt><dd class="font-semibold text-brand-700">{{ $link?->discount_percent !== null ? number_format((float) $link->discount_percent, 2, '.', '').'%' : 'Не указана' }}</dd></div>
        </dl>
        <x-ui.button type="button" variant="secondary" class="mt-auto w-full" onclick="document.getElementById('partner-{{ $supplier->id }}').showModal()">Подробнее</x-ui.button>
    </x-ui.card>
    <dialog id="partner-{{ $supplier->id }}" aria-labelledby="partner-title-{{ $supplier->id }}" class="m-auto w-[min(92vw,36rem)] max-h-[85vh] overflow-y-auto rounded-panel border border-slate-200 bg-white p-5 text-ink shadow-panel backdrop:bg-slate-900/50">
        <div class="flex items-start justify-between gap-3"><div><h2 id="partner-title-{{ $supplier->id }}" class="text-xl font-bold">{{ $supplier->name }}</h2><p class="text-sm text-muted">{{ $supplier->city ?: 'Город не указан' }}</p></div><form method="dialog"><x-ui.button variant="ghost" aria-label="Закрыть">Закрыть</x-ui.button></form></div>
        <div class="mt-5 space-y-2 text-sm"><p>Контактное лицо: {{ $supplier->contact_name ?: '—' }}</p><p>Телефоны: {{ $phones ? implode(', ', $phones) : '—' }}</p><p>WhatsApp: {{ $supplier->whatsapp_phone ?: '—' }}</p><p>Email: {{ $email ?: '—' }}</p><p>Последний прайс-лист: {{ $import ? ($import->received_at ?? $import->created_at)->timezone('Asia/Dushanbe')->format('d.m.Y H:i') : '—' }}</p><p>Статус: {{ $import?->status->label() ?? 'Нет прайс-листа' }}</p></div>
        @if($link)<form method="post" action="{{ route('partners.discount', $supplier) }}" class="mt-5 space-y-3 border-t border-slate-200 pt-5">@csrf @method('PATCH')<x-ui.input name="discount_percent" type="number" min="0" max="100" step="0.01" label="Согласованная скидка, %" :value="$link->discount_percent" required /><p class="text-xs text-muted">Скидка сохраняется как договорённость и пока не меняет цены в каталоге и при оформлении заказа.</p><x-ui.button>Сохранить скидку</x-ui.button></form>@else<p class="mt-5 text-sm text-muted">Добавьте поставщика по телефону организации или коду приглашения, чтобы указать скидку.</p>@endif
    </dialog>
@empty
    <div class="md:col-span-2 xl:col-span-3"><x-ui.empty-state title="Поставщики не найдены" description="Попробуйте изменить поиск или город." /></div>
@endforelse
</div>
<div class="mt-6">{{ $suppliers->links() }}</div>
@endsection
