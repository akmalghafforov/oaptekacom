@extends('layouts.app')
@section('content')
<x-ui.page-header title="Регистрация аптеки" description="После проверки заявки вы получите доступ к платформе." />
<x-ui.card><form method="post" class="space-y-4">@csrf
<x-ui.input name="pharmacy_name" label="Название аптеки" required autocomplete="organization" />
<x-ui.input name="phone" label="Телефон" hint="Подтвердим номер кодом SMS." required autocomplete="tel" />
<x-ui.button class="w-full">Получить код</x-ui.button>
</form><p class="mt-5 text-center text-sm text-muted">Уже зарегистрированы? <a class="font-semibold text-brand-700 hover:underline" href="{{ route('login') }}">Войти</a>.</p></x-ui.card>
@endsection
