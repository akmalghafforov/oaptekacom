@extends('layouts.app')
@section('content')
<x-ui.page-header title="Вход" description="Аптеки входят по номеру телефона; сотрудники — по email и паролю." />
<div class="grid gap-5 lg:grid-cols-2"><x-ui.card><form method="post" action="{{ route('login.otp.send') }}" class="space-y-5">@csrf
<x-ui.input name="phone" label="Телефон аптеки" hint="Например, +992901234567" required autocomplete="tel" autofocus />
<x-ui.button class="w-full">Получить код</x-ui.button>
</form></x-ui.card><x-ui.card><form method="post" action="{{ route('login') }}" class="space-y-5">@csrf
<x-ui.input name="email" type="email" label="Email" required autocomplete="email" autofocus />
<x-ui.input name="password" type="password" label="Пароль" required autocomplete="current-password" />
<x-ui.button class="w-full" variant="secondary">Войти сотруднику</x-ui.button>
</form></x-ui.card></div><p class="mt-5 text-center text-sm text-muted">Нет аккаунта? <a class="font-semibold text-brand-700 hover:underline" href="{{ route('register') }}">Подайте заявку</a>.</p>
@endsection
