@extends('layouts.app')
@section('content')
<x-ui.page-header title="Двухфакторная защита" description="Администраторам необходимо подтвердить TOTP-код." />
<x-ui.card><p class="mb-4 text-sm text-slate-600">Добавьте этот секрет в приложение-аутентификатор: <code class="font-semibold">{{ $secret }}</code></p><form method="post" action="{{ route('two-factor.confirm') }}" class="space-y-4">@csrf<x-ui.input name="code" label="Одноразовый код" inputmode="numeric" required autofocus /><x-ui.button>Подтвердить</x-ui.button></form><form method="post" action="{{ route('two-factor.recovery') }}" class="mt-6 space-y-4 border-t pt-5">@csrf<x-ui.input name="code" label="Код восстановления" /><x-ui.button variant="secondary">Использовать код восстановления</x-ui.button></form></x-ui.card>
@endsection
