@extends('layouts.app')
@section('content')
<x-ui.page-header title="Вход для аптек" description="Введите номер телефона, привязанный к вашей аптеке." />
<x-ui.card><form method="post" action="{{ route('login.otp.send') }}" class="space-y-5">@csrf
<x-ui.input name="phone" label="Телефон аптеки" hint="Формат: +992 (90) 123-45-67" value="+992" mask-placeholder="+992 (00) 000-00-00" required autocomplete="tel" inputmode="tel" data-phone-mask data-phone-mask-default autofocus />
<x-ui.button class="w-full">Получить код</x-ui.button>
</form><p class="mt-5 text-center text-sm text-muted">Нет аккаунта? <a class="font-semibold text-brand-700 hover:underline" href="{{ route('register') }}">Подайте заявку</a>.</p></x-ui.card>
<div class="mt-5 flex flex-wrap justify-center gap-x-4 gap-y-2 text-sm text-muted"><a class="font-semibold text-brand-700 hover:underline" href="{{ route('provider.login') }}">Вход для поставщиков</a><a class="font-semibold text-brand-700 hover:underline" href="{{ route('admin.login') }}">Вход для администраторов</a></div>
@endsection
