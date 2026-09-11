@extends('layouts.app')
@section('content')
<x-ui.page-header title="Вход для поставщика" description="Используйте учётные данные вашей организации-поставщика." />
<x-ui.card><form method="post" action="{{ route('provider.login.authenticate') }}" class="space-y-5">@csrf
<x-ui.input name="email" type="email" label="Email" required autocomplete="email" autofocus />
<x-ui.input name="password" type="password" label="Пароль" required autocomplete="current-password" />
<x-ui.button class="w-full">Войти</x-ui.button>
</form></x-ui.card>
<p class="mt-5 text-center text-sm text-muted"><a class="font-semibold text-brand-700 hover:underline" href="{{ route('login') }}">Вход для аптек</a></p>
@endsection
