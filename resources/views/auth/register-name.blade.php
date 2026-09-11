@extends('layouts.app')
@section('content')
<x-ui.page-header :title="$title" description="Номер {{ $phone }} ещё не зарегистрирован. {{ $description }}" />
<x-ui.card><form method="post" action="{{ route($route) }}" class="space-y-4">@csrf
<x-ui.input :name="$field" :label="$label" required autocomplete="organization" />
<x-ui.button class="w-full">Получить код SMS</x-ui.button>
</form></x-ui.card>
@endsection
