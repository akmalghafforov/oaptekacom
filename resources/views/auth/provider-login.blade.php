@extends('layouts.app')
@section('content')
<x-ui.page-header :title="$registration ? 'Заявка поставщика' : 'Вход для поставщика'" :description="$registration ? 'Укажите телефон для регистрации компании.' : 'Введите номер телефона, привязанный к вашей компании.'" />
<x-ui.card><form method="post" action="{{ route($registration ? 'provider.register.phone' : 'provider.otp.send') }}" class="space-y-5">@csrf
<x-ui.input name="phone" label="Телефон поставщика" hint="Формат: +992 (90) 123-45-67" value="+992" mask-placeholder="+992 (00) 000-00-00" required autocomplete="tel" inputmode="tel" data-phone-mask data-phone-mask-default autofocus />
<x-ui.button class="w-full">Получить код</x-ui.button>
</form>@unless($registration)<p class="mt-5 text-center text-sm text-muted">Нет аккаунта? <a class="font-semibold text-brand-700 hover:underline" href="{{ route('provider.register') }}">Подайте заявку</a>.</p>@endunless</x-ui.card>
<p class="mt-5 text-center text-sm text-muted"><a class="font-semibold text-brand-700 hover:underline" href="{{ route('login') }}">Вход для аптек</a></p>
@endsection
