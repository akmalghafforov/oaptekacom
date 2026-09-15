@extends('layouts.app')
@section('content')
<div data-catalog-search data-search-url="{{ route('catalog.search') }}">
    <x-ui.page-header title="Каталог лекарств" description="Сравнивайте актуальные предложения поставщиков. Цена указана в TJS." />
    <x-ui.card class="mb-6">
        <form data-catalog-form class="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_auto] md:items-end">
            <x-ui.input name="q" label="Поиск" :value="request('q')" placeholder="Название, МНН или форма" hint="Поиск начинается с трёх символов." autocomplete="off" />
            <x-ui.input name="city" label="Город" :value="request('city')" placeholder="Например, Душанбе" autocomplete="off" />
            <x-ui.button type="button" variant="secondary" data-catalog-reset>Сбросить</x-ui.button>
        </form>
    </x-ui.card>
    <div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
        <aside>
            <x-ui.card class="lg:sticky lg:top-4">
                <div class="flex items-center justify-between gap-3"><h2 class="font-semibold text-ink">Категории</h2><button type="button" data-category-clear class="min-h-10 rounded-control px-2 text-sm font-medium text-brand-700 hover:bg-brand-50">Все</button></div>
                <div data-category-list class="mt-3 grid gap-1" aria-label="Фильтр по категории"><p class="text-sm text-muted">Категории появятся после поиска.</p></div>
            </x-ui.card>
        </aside>
        <section aria-busy="false" data-catalog-region>
            <div class="sr-only" aria-live="polite" data-catalog-announcer></div>
            <div data-catalog-state><x-ui.empty-state title="Начните поиск" description="Введите не менее трёх символов названия лекарства." /></div>
            <div data-catalog-results class="hidden grid gap-4 md:grid-cols-2 xl:grid-cols-3"></div>
            <div data-catalog-load-status class="mt-5 hidden text-center text-sm text-muted" aria-live="polite"></div>
            <div data-catalog-sentinel class="h-1" aria-hidden="true"></div>
        </section>
    </div>
</div>
@endsection
