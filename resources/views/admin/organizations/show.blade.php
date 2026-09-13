@extends('layouts.app')

@section('content')
@php
    $isProvider = $type === \App\Enums\OrganizationType::Wholesaler;
    $prefix = $isProvider ? 'admin.providers' : 'admin.pharmacies';
@endphp
<x-ui.page-header :title="$organization->name" :description="$isProvider ? 'Карточка поставщика.' : 'Карточка аптеки.'">
    <x-slot:actions><div class="flex flex-wrap gap-3"><x-ui.button variant="ghost" :href="route($prefix.'.index')">К каталогу</x-ui.button><x-ui.button :href="route($prefix.'.edit', $organization)">Редактировать</x-ui.button></div></x-slot:actions>
</x-ui.page-header>
<div class="grid gap-6 lg:grid-cols-3">
    <x-ui.card class="lg:col-span-2">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div><dt class="text-sm text-muted">Статус</dt><dd class="mt-1"><x-ui.status-badge :status="$organization->status" /></dd></div>
            <div><dt class="text-sm text-muted">Создана</dt><dd class="mt-1 font-medium">{{ $organization->created_at->format('d.m.Y H:i') }}</dd></div>
            <div><dt class="text-sm text-muted">Город</dt><dd class="mt-1 font-medium">{{ $organization->city ?: '—' }}</dd></div>
            <div><dt class="text-sm text-muted">Телефон организации</dt><dd class="mt-1 font-medium">{{ $organization->phone ?: '—' }}</dd></div>
            @if($isProvider)<div><dt class="text-sm text-muted">Режим поставщика</dt><dd class="mt-1 font-medium">{{ $organization->supplier_mode === 'both' ? 'Покупатель и поставщик' : 'Поставщик' }}</dd></div><div><dt class="text-sm text-muted">Минимальный заказ</dt><dd class="mt-1 font-medium">{{ $organization->minimum_order }}</dd></div><div class="sm:col-span-2"><dt class="text-sm text-muted">Условия доставки</dt><dd class="mt-1 whitespace-pre-line font-medium">{{ $organization->delivery_conditions ?: '—' }}</dd></div>@endif
        </dl>
    </x-ui.card>
    @if($isProvider)<x-ui.card><h2 class="text-lg font-bold">Импорт прайс-листа</h2><p class="mt-1 text-sm text-muted">Настройте профиль и формат входящих прайс-листов.</p><x-ui.button class="mt-4" variant="secondary" :href="route('admin.supplier-import-profiles.edit', $organization)">Открыть профиль</x-ui.button></x-ui.card>@endif
</div>
<x-ui.card class="mt-6"><h2 class="text-lg font-bold">Связанные учётные записи</h2><div class="table-wrap mt-4"><table class="data-table"><thead><tr><th>Имя</th><th>Email</th><th>Телефон</th><th>Статус</th></tr></thead><tbody>@forelse($organization->users as $user)<tr><td class="font-medium">{{ $user->name }}</td><td>{{ $user->email ?: '—' }}</td><td>{{ $user->phone ?: '—' }}</td><td>{{ $user->is_blocked ? 'Заблокирована' : 'Активна' }}</td></tr>@empty<tr><td colspan="4">Нет связанных учётных записей.</td></tr>@endforelse</tbody></table></div></x-ui.card>
@endsection
