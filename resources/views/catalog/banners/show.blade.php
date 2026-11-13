@extends('layouts.app')
@section('content')
<x-ui.page-header :title="$banner->title"><x-slot:actions><x-ui.button :href="route('catalog')" variant="secondary">В каталог</x-ui.button></x-slot:actions></x-ui.page-header>
<x-ui.card class="space-y-6">
    <p class="whitespace-pre-wrap break-words">{{ $banner->description }}</p>
    <x-ui.button :href="$banner->whatsappUrl()" target="_blank" rel="noopener noreferrer">Связаться в WhatsApp</x-ui.button>
</x-ui.card>
@endsection
