@extends('layouts.app')
@section('document-language', $locale)
@section('content')
@php
    $isTajik = $locale === 'tg';
    $title = $document === 'privacy'
        ? ($isTajik ? 'Сиёсати махфият' : 'Политика конфиденциальности')
        : ($isTajik ? 'Шартҳои истифода' : 'Условия использования');
@endphp
<div class="mx-auto max-w-3xl space-y-6" lang="{{ $locale }}">
    <x-ui.page-header :title="$title" description="OAPTEKA" />
    <nav class="flex flex-wrap gap-2" aria-label="{{ $isTajik ? 'Забони ҳуҷҷат' : 'Язык документа' }}">
        <x-ui.button variant="secondary" :href="route('legal.'.$document, ['locale' => 'ru'])" lang="ru" hreflang="ru" :aria-current="$locale === 'ru' ? 'page' : null">Русский</x-ui.button>
        <x-ui.button variant="secondary" :href="route('legal.'.$document, ['locale' => 'tg'])" lang="tg" hreflang="tg" :aria-current="$locale === 'tg' ? 'page' : null">Тоҷикӣ</x-ui.button>
    </nav>
    @if($isDraft)
        <x-ui.alert type="warning">{{ $isTajik ? 'Лоиҳа. Маълумоти оператор, суроға, тамос ва ҷойгиршавии хостинг ҳанӯз пурра тасдиқ нашудааст. Ҳуҷҷат ҳамчун таҳрири ниҳоӣ нашр нашудааст.' : 'Проект. Сведения об операторе, адресе, контактах и размещении хостинга ещё не полностью подтверждены. Документ не опубликован как окончательная редакция.' }}</x-ui.alert>
    @endif
    <x-ui.card>
        <dl class="grid gap-3 break-words text-sm sm:grid-cols-2">
            @foreach([
                ($isTajik ? 'Санаи ҳуҷҷат' : 'Дата документа') => $legal['document_date'],
                ($isTajik ? 'Таҳрир' : 'Версия') => $legal['document_version'],
                ($isTajik ? 'Санаи эътибор' : 'Дата вступления в силу') => $isDraft ? ($isTajik ? 'Ҳанӯз эътибор надорад' : 'Ещё не вступил в силу') : $legal['effective_date'],
                ($isTajik ? 'Оператор' : 'Оператор') => $legal['legal_name'],
                ($isTajik ? 'Суроғаи почта' : 'Почтовый адрес') => $legal['postal_address'],
                ($isTajik ? 'Тамос барои муроҷиатҳо' : 'Контакт для обращений') => $legal['privacy_email'],
                ($isTajik ? 'Кишвари хостинг' : 'Страна хостинга') => $legal['hosting_country'],
            ] as $label => $value)
                <div><dt class="text-muted">{{ $label }}</dt><dd>{{ $value ?: ($isTajik ? 'Ҳанӯз муайян нашудааст' : 'Пока не указан') }}</dd></div>
            @endforeach
        </dl>
    </x-ui.card>
    <x-ui.card class="space-y-6 break-words leading-relaxed">
        @include('legal.'.$document.'-'.$locale)
    </x-ui.card>
    <x-ui.button variant="secondary" :href="route('legal.'.($document === 'privacy' ? 'terms' : 'privacy'), ['locale' => $locale])">{{ $document === 'privacy' ? ($isTajik ? 'Шартҳои истифода' : 'Условия использования') : ($isTajik ? 'Сиёсати махфият' : 'Политика конфиденциальности') }}</x-ui.button>
</div>
@endsection
