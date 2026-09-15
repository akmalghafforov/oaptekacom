@extends('layouts.app')
@section('content')
<div data-catalog-search data-search-url="{{ route('catalog.search') }}" data-cart-total="{{ $cartTotal }}">
    <x-ui.card class="catalog-search-panel sticky top-2 z-20 mb-5 overflow-visible" data-search-panel>
        <div data-search-expanded><p class="text-sm font-semibold uppercase tracking-wider text-brand-700">Оптовый каталог</p><h1 class="mt-1 text-2xl font-bold sm:text-3xl">Поиск товаров</h1><p class="mt-2 max-w-2xl text-sm text-muted">Сравнивайте цены, остатки и сроки годности в актуальных прайс-листах поставщиков.</p></div>
        <form data-catalog-form class="mt-5 grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto_auto] lg:items-end">
            <x-ui.search-input name="q" label="Название лекарства" :value="request('q')" placeholder="Название, МНН, форма или дозировка" hint="Оставьте поле пустым, чтобы посмотреть весь каталог." autocomplete="off" />
            <x-ui.button type="button" variant="secondary" data-filter-open>Фильтры <span data-filter-count class="hidden rounded-full bg-brand-100 px-2 py-0.5 text-xs">0</span></x-ui.button>
            <x-ui.button type="submit" data-search-submit><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg> Найти</x-ui.button>
        </form>
        <div data-search-expanded class="mt-4 flex flex-wrap gap-2"><x-ui.button type="button" variant="secondary" disabled>AI-закупка · скоро</x-ui.button>@if($canBuy)<x-ui.button href="{{ route('cart') }}" variant="secondary">Корзина <span data-cart-badge>{{ $cartTotal }}</span></x-ui.button>@endif</div>
    </x-ui.card>
    <div data-search-sentinel class="h-px" aria-hidden="true"></div>
    <section aria-labelledby="catalog-categories-title" class="mb-5"><div class="mb-3 flex flex-wrap items-center justify-between gap-3"><h2 id="catalog-categories-title" class="text-lg font-bold">Категории</h2><div class="flex rounded-control border border-slate-200 bg-white p-1" role="group" aria-label="Вид результатов"><button type="button" data-view="list" aria-pressed="true" class="catalog-view-button">Список</button><button type="button" data-view="grid" aria-pressed="false" class="catalog-view-button">Карточки</button><button type="button" data-view="suppliers" aria-pressed="false" class="catalog-view-button">Поставщики</button></div></div>
        <div data-category-list class="catalog-category-strip" role="listbox" aria-label="Категории товаров"><button type="button" data-category="" role="option" aria-selected="true" class="catalog-category-card"><span class="grid size-12 place-items-center rounded-full bg-brand-50 text-brand-700">Все</span><span class="font-semibold">Все товары</span><span data-all-count class="text-xs text-muted">—</span></button>@foreach($categories as $category)<button type="button" data-category="{{ $category->id }}" role="option" aria-selected="false" class="catalog-category-card"><span class="grid size-12 place-items-center rounded-full bg-slate-100 text-brand-700"><x-ui.catalog-category-icon :code="$category->code" /></span><span class="font-semibold">{{ $category->label }}</span><span data-category-count class="text-xs text-muted">—</span></button>@endforeach</div>
    </section>
    <section aria-busy="false" data-catalog-region><div class="sr-only" aria-live="polite" data-catalog-announcer></div><div class="mb-3 flex items-center justify-between gap-3"><h2 class="text-lg font-bold">Предложения</h2><p data-result-count class="text-sm text-muted"></p></div><div data-catalog-state></div>
        <div data-list-view class="hidden"><div class="table-wrap hidden lg:block"><table class="data-table"><thead><tr><th>Фото</th><th>Товар</th><th>Производитель</th><th>Срок</th><th>Поставщик</th><th>Цена</th><th>Заказ</th></tr></thead><tbody data-catalog-table></tbody></table></div><div data-catalog-cards class="grid gap-4 lg:hidden"></div></div>
        <div data-grid-view class="hidden"><div data-catalog-grid class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"></div></div>
        <div data-suppliers-view class="hidden"><div data-catalog-suppliers class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"></div></div>
        <div class="mt-6 text-center"><x-ui.button type="button" variant="secondary" data-load-more class="hidden">Загрузить ещё</x-ui.button><p data-catalog-load-status class="mt-2 text-sm text-muted" aria-live="polite"></p></div>
    </section>
    @include('catalog.partials.filters-dialog')
</div>
@endsection
