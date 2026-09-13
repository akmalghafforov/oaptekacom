@extends('layouts.app')

@section('content')
@php
    $isProvider = $type === \App\Enums\OrganizationType::Wholesaler;
    $title = $isProvider ? 'Поставщики' : 'Аптеки';
    $prefix = $isProvider ? 'admin.providers' : 'admin.pharmacies';
    $nextDirection = fn (string $column) => $sort === $column && $direction === 'asc' ? 'desc' : 'asc';
@endphp
<x-ui.page-header :title="$title" :description="$isProvider ? 'Каталог поставщиков и связанных учётных записей.' : 'Каталог аптек и связанных учётных записей.'">
    <x-slot:actions><a class="text-sm font-semibold text-brand-700 hover:underline" href="{{ route('admin.users') }}">Пользователи</a></x-slot:actions>
</x-ui.page-header>

<x-ui.card class="mb-6">
    <form method="get" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <x-ui.input name="name" label="Название" :value="request('name')" />
        <x-ui.input name="email" label="Email учётной записи" :value="request('email')" />
        <x-ui.input name="phone" label="Телефон организации или учётной записи" :value="request('phone')" />
        <x-ui.select name="status" label="Статус"><option value="">Все статусы</option>@foreach(['active' => 'Активна', 'blocked' => 'Заблокирована', 'pending' => 'Ожидает проверки'] as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</x-ui.select>
        <x-ui.input name="created_from" type="date" label="Создана с" :value="request('created_from')" />
        <x-ui.input name="created_to" type="date" label="Создана по" :value="request('created_to')" />
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="direction" value="{{ $direction }}">
        <div class="flex flex-wrap items-end gap-3"><x-ui.button>Применить</x-ui.button><x-ui.button variant="ghost" :href="route($prefix.'.index')">Сбросить фильтры</x-ui.button></div>
    </form>
</x-ui.card>

<div class="table-wrap">
    <table class="data-table">
        <thead><tr>
            <th><a class="hover:underline" href="{{ route($prefix.'.index', array_merge(request()->except('page'), ['sort' => 'name', 'direction' => $nextDirection('name')])) }}">Название</a></th>
            <th>Город и телефон</th>
            <th><a class="hover:underline" href="{{ route($prefix.'.index', array_merge(request()->except('page'), ['sort' => 'status', 'direction' => $nextDirection('status')])) }}">Статус</a></th>
            @if($isProvider)<th>Режим поставщика</th>@endif
            <th><a class="hover:underline" href="{{ route($prefix.'.index', array_merge(request()->except('page'), ['sort' => 'created_at', 'direction' => $nextDirection('created_at')])) }}">Создана</a></th>
            <th>Учётные записи</th>
            <th class="text-right">Действие</th>
        </tr></thead>
        <tbody>@forelse($organizations as $organization)<tr>
            <td class="font-medium">{{ $organization->name }}</td>
            <td>{{ $organization->city ?: '—' }}<span class="mt-1 block text-sm text-muted">{{ $organization->phone ?: '—' }}</span></td>
            <td><x-ui.status-badge :status="$organization->status" /></td>
            @if($isProvider)<td>{{ $organization->supplier_mode === 'both' ? 'Покупатель и поставщик' : 'Поставщик' }}</td>@endif
            <td>{{ $organization->created_at->format('d.m.Y') }}</td>
            <td>@forelse($organization->users as $user)<div class="mb-1">{{ $user->name }}<span class="block text-sm text-muted">{{ $user->email ?: $user->phone ?: '—' }}</span></div>@empty — @endforelse</td>
            <td class="text-right"><x-ui.button variant="secondary" :href="route($prefix.'.show', $organization)">Открыть</x-ui.button></td>
        </tr>@empty<tr><td colspan="{{ $isProvider ? 7 : 6 }}"><x-ui.empty-state :title="$isProvider ? 'Поставщики не найдены' : 'Аптеки не найдены'" description="Измените параметры поиска или сбросьте фильтры." /></td></tr>@endforelse</tbody>
    </table>
</div>
<div class="mt-6">{{ $organizations->links() }}</div>
@endsection
