@extends('layouts.app')
@section('content')
<x-ui.page-header title="Операционный центр" description="Проверка заявок и управление доступом к тарифам."><x-slot:actions><div class="flex gap-3"><a class="text-sm font-semibold text-brand-700 hover:underline" href="{{ route('admin.users') }}">Пользователи</a><a class="text-sm font-semibold text-brand-700 hover:underline" href="{{ route('admin.subscriptions.index') }}">Подписки</a></div></x-slot:actions></x-ui.page-header>
<section><h2 class="mb-3 text-lg font-bold">Ожидают одобрения</h2><div class="space-y-3">@forelse($pending as $organization)<x-ui.card><p class="font-semibold">{{ $organization->name }}</p><p class="mt-1 text-sm text-muted">{{ $organization->phone }}</p><form method="post" action="{{ route('admin.approve', $organization) }}" class="mt-4">@csrf <x-ui.button>Одобрить организацию</x-ui.button></form></x-ui.card>@empty<x-ui.empty-state title="Нет заявок" />@endforelse</div></section>
@endsection
