@extends('layouts.app')
@section('content')
<x-ui.page-header title="Регистрация аптеки" description="После создания заявки отправим пароль по SMS. Он подтвердит номер и сохранится для будущих входов." />
<x-ui.card><form method="post" class="space-y-4">@csrf <x-mode-version />
<x-ui.input name="phone" label="Телефон" hint="Сохраните пароль из SMS для будущих входов." required autocomplete="tel" />
<x-ui.button class="w-full">Продолжить</x-ui.button>
</form><x-registration-legal-links /><p class="mt-5 text-center text-sm text-muted">Уже зарегистрированы? <a class="font-semibold text-accent hover:underline" href="{{ route('login') }}">Войти</a>.</p></x-ui.card>
@endsection
