@extends('layouts.app')
@section('content')
<x-ui.page-header :title="$title" description="Номер {{ $phone }} ещё не зарегистрирован. {{ $description }}" />
<x-ui.card><form method="post" action="{{ route($route) }}" class="space-y-4">@csrf <x-mode-version />
@if($field === 'pharmacy_name')
<x-ui.select name="city" label="Город аптеки" required>
<option value="">Выберите город</option>
@foreach($cities as $city)
<option value="{{ $city }}" @selected(old('city') === $city)>{{ $city }}</option>
@endforeach
</x-ui.select>
@endif
<x-ui.input :name="$field" :label="$label" :hint="$field === 'pharmacy_name' ? 'Буквы, цифры и пробелы; хотя бы одна буква.' : null" required autocomplete="organization" />
<x-ui.button class="w-full">Получить код SMS</x-ui.button>
</form><x-registration-legal-links /></x-ui.card>
@endsection
