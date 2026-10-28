@extends('layouts.app')
@section('content')
<x-ui.page-header title="Новый пароль по SMS" description="Новый пароль заменит предыдущий. Сохраните его для будущих входов." />
<x-ui.card>
<form method="post" action="{{ route('login.password.send') }}" class="space-y-5">@csrf <x-mode-version />
<x-ui.input name="phone" label="Телефон аптеки" hint="Формат: +992 (90) 123-45-67" value="+992" mask-placeholder="+992 (00) 000-00-00" required autocomplete="tel" inputmode="tel" data-phone-mask data-phone-mask-default autofocus />
<x-ui.button class="w-full">Отправить новый пароль</x-ui.button>
</form>
<x-ui.button variant="ghost" :href="route('login')">Вернуться ко входу</x-ui.button>
</x-ui.card>
@endsection
