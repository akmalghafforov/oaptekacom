@extends('layouts.app')
@section('content')
<x-ui.page-header :title="$title" description="Введите шестизначный код из SMS." />
<x-ui.card><form method="post" action="{{ route($route) }}" class="space-y-4">@csrf
<x-ui.input name="phone" label="Телефон" :value="$phone" required readonly data-phone-mask />
<x-ui.input name="code" label="Код из SMS" hint="Введите 6 цифр." inputmode="numeric" autocomplete="one-time-code" maxlength="7" required data-sms-code-mask autofocus />
<x-ui.button class="w-full">Подтвердить</x-ui.button>
</form><form method="post" action="{{ route($resend_route) }}" class="mt-4">@csrf @if($resend_route === 'profile.phone.send')<input type="hidden" name="phone" value="{{ $phone }}">@endif <x-ui.button variant="ghost" class="w-full">Отправить код повторно</x-ui.button></form></x-ui.card>
@endsection
