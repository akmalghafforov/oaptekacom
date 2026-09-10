@extends('layouts.app')
@section('content')
<x-ui.page-header title="Регистрация аптеки" description="После проверки заявки вы получите доступ к платформе." />
<x-ui.card><form method="post" class="space-y-4">@csrf
<x-ui.input name="pharmacy_name" label="Название аптеки" required autocomplete="organization" />
<x-ui.input name="phone" label="Телефон" required autocomplete="tel" />
<x-ui.input name="email" type="email" label="Email" required autocomplete="email" />
<x-ui.input name="password" type="password" label="Пароль" hint="Используйте надёжный пароль." required autocomplete="new-password" />
<x-ui.input name="password_confirmation" type="password" label="Повторите пароль" required autocomplete="new-password" />
<x-ui.button class="w-full">Отправить заявку</x-ui.button>
</form><p class="mt-5 text-center text-sm text-muted">Уже зарегистрированы? <a class="font-semibold text-brand-700 hover:underline" href="{{ route('login') }}">Войти</a>.</p></x-ui.card>
@endsection
