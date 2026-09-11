@extends('layouts.app')
@section('content')
<x-ui.page-header title="Регистрация аптеки" description="Сначала найдём ваш номер, затем подтвердим его кодом SMS." />
<x-ui.card><form method="post" class="space-y-4">@csrf
<x-ui.input name="phone" label="Телефон" hint="Подтвердим номер кодом SMS." required autocomplete="tel" />
<x-ui.button class="w-full">Продолжить</x-ui.button>
</form><p class="mt-5 text-center text-sm text-muted">Уже зарегистрированы? <a class="font-semibold text-brand-700 hover:underline" href="{{ route('login') }}">Войти</a>.</p></x-ui.card>
@endsection
