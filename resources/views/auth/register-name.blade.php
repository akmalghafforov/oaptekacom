@extends('layouts.app')
@section('content')
<x-ui.page-header title="Название аптеки" description="Номер {{ $phone }} ещё не зарегистрирован. Укажите название для новой заявки." />
<x-ui.card><form method="post" action="{{ route('register.details.store') }}" class="space-y-4">@csrf
<x-ui.input name="pharmacy_name" label="Название аптеки" required autocomplete="organization" />
<x-ui.button class="w-full">Получить код SMS</x-ui.button>
</form></x-ui.card>
@endsection
