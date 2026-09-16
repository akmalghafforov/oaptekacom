@extends('layouts.app')
@section('content')
<x-ui.card class="space-y-6">
<div class="flex flex-col items-center gap-4 text-center">
<div class="grid size-12 place-items-center rounded-full bg-amber-100 text-warning" aria-hidden="true">
<svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.3 3.6 2.4 17.3A2 2 0 0 0 4.1 20h15.8a2 2 0 0 0 1.7-2.7L13.7 3.6a2 2 0 0 0-3.4 0Z" /></svg>
</div>
<div class="space-y-2">
<h1 class="text-xl font-semibold tracking-tight text-ink sm:text-2xl">Активный сеанс на другом устройстве</h1>
<p class="text-sm leading-6 text-muted">Эта учётная запись уже используется. Проверьте данные сеанса перед входом.</p>
</div>
</div>

<x-ui.alert type="warning">OAPTEKA не завершает другие сеансы автоматически. После подтверждения предыдущий сеанс будет завершён.</x-ui.alert>

<div class="rounded-panel border border-slate-200 bg-slate-50 p-4">
<div class="flex flex-wrap items-start justify-between gap-3">
<div class="min-w-0">
<p class="text-xs font-semibold uppercase tracking-wide text-muted">Устройство</p>
<p class="mt-1 break-words font-semibold text-ink">{{ $deviceSummary }}</p>
</div>
<x-ui.status-badge status="active" label="Активен" />
</div>
<dl class="mt-4 grid gap-4 border-t border-slate-200 pt-4 text-sm sm:grid-cols-2">
<div><dt class="font-medium text-muted">IP-адрес</dt><dd class="mt-1 text-ink">{{ $session->ip_address ?: 'Неизвестно' }}</dd></div>
<div><dt class="font-medium text-muted">Последняя активность</dt><dd class="mt-1 text-ink">{{ $lastActivity }}</dd></div>
</dl>
</div>

<div class="grid gap-3">
<form method="post" action="{{ route($cancelRoute) }}">@csrf <x-ui.button variant="secondary" class="w-full">Назад ко входу</x-ui.button></form>
<form method="post" action="{{ route($confirmRoute) }}">@csrf <x-ui.button variant="danger" class="w-full">Выйти с другого устройства и войти</x-ui.button></form>
</div>
</x-ui.card>
@endsection
