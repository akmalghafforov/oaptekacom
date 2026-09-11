@extends('layouts.app')
@section('content')
<x-ui.page-header title="Активный сеанс обнаружен" description="Ваша учётная запись уже используется на другом устройстве." />
<x-ui.card class="space-y-5">
<x-ui.alert type="warning">Для продолжения необходимо завершить сеанс на другом устройстве.</x-ui.alert>
<dl class="grid gap-4 text-sm sm:grid-cols-2"><div><dt class="font-medium text-muted">Браузер / ОС</dt><dd class="mt-1 break-words text-ink">{{ $session->user_agent ?: 'Неизвестно' }}</dd></div><div><dt class="font-medium text-muted">IP-адрес</dt><dd class="mt-1 text-ink">{{ $session->ip_address ?: 'Неизвестно' }}</dd></div><div><dt class="font-medium text-muted">Последняя активность</dt><dd class="mt-1 text-ink">{{ $lastActivity }}</dd></div></dl>
<form method="post" action="{{ route('login.session.confirm') }}">@csrf <x-ui.button class="w-full">Выйти на другом устройстве и продолжить</x-ui.button></form>
<form method="post" action="{{ route('login.session.cancel') }}">@csrf <x-ui.button variant="ghost" class="w-full">Отмена</x-ui.button></form>
</x-ui.card>
@endsection
