@extends('layouts.app')
@section('content')
<x-ui.page-header title="Вход" description="Войдите, чтобы работать с заказами и поставщиками." />
<x-ui.card><form method="post" class="space-y-5">@csrf
<x-ui.input name="email" type="email" label="Email" required autocomplete="email" autofocus />
<x-ui.input name="password" type="password" label="Пароль" required autocomplete="current-password" />
<x-ui.button class="w-full">Войти</x-ui.button>
</form><p class="mt-5 text-center text-sm text-muted">Нет аккаунта? <a class="font-semibold text-brand-700 hover:underline" href="{{ route('register') }}">Подайте заявку</a>.</p></x-ui.card>
@endsection
