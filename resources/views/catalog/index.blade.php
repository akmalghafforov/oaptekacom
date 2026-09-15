@extends('layouts.app')
@section('content')
<div data-catalog-search data-search-url="{{ route('catalog.search') }}" data-cart-total="{{ $cartTotal }}">
    <x-ui.card class="catalog-search-panel sticky top-2 z-20 mb-5 overflow-visible transition-[padding] [&.is-compact]:p-2" data-search-panel>
        <div data-search-expanded class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-2xl font-bold sm:text-3xl">Поиск товаров</h1>
                <p class="mt-1 max-w-2xl text-sm text-muted">Сравнивайте цены, остатки и сроки годности в актуальных прайс-листах поставщиков.</p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2" data-search-actions>
                <x-ui.button type="button" variant="secondary" disabled>AI-закупка · скоро</x-ui.button>
                @if($canBuy)
                    <x-ui.button href="{{ route('cart') }}" variant="secondary" class="gap-2">Корзина <span data-cart-badge>{{ $cartTotal }}</span></x-ui.button>
                @endif
            </div>
        </div>
        <form data-catalog-form class="mt-4 grid grid-cols-[minmax(0,1fr)_auto_auto] items-end gap-2">
            <x-ui.search-input name="q" label="Название лекарства" :value="request('q')" placeholder="Название, МНН, форма или дозировка" autocomplete="off" />
            <x-ui.button type="button" variant="secondary" class="min-h-12 gap-2 px-3 sm:px-4" data-filter-open aria-label="Фильтры">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
                <span class="hidden sm:inline">Фильтры</span>
                <span data-filter-count class="hidden rounded-full bg-brand-100 px-2 py-0.5 text-xs">0</span>
            </x-ui.button>
            <x-ui.button type="submit" class="min-h-12 gap-2 px-3 sm:px-4" data-search-submit aria-label="Найти">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <span class="hidden sm:inline">Найти</span>
            </x-ui.button>
        </form>
    </x-ui.card>
    <div data-search-sentinel class="h-px" aria-hidden="true"></div>
    <section aria-labelledby="catalog-categories-title" class="mb-5">
        <h2 id="catalog-categories-title" class="sr-only">Категории</h2>
        <div class="catalog-toolbar" data-catalog-toolbar>
            <div class="catalog-view-selector" role="group" aria-label="Вид результатов">
                <button type="button" data-view="list" aria-pressed="true" aria-label="Список" title="Список" class="catalog-view-button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13"/><circle cx="4" cy="6" r="1" fill="currentColor" stroke="none"/><circle cx="4" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="4" cy="18" r="1" fill="currentColor" stroke="none"/></svg>
                    <span class="sr-only">Список</span>
                </button>
                <button type="button" data-view="grid" aria-pressed="false" aria-label="Карточки" title="Карточки" class="catalog-view-button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span class="sr-only">Карточки</span>
                </button>
                <button type="button" data-view="suppliers" aria-pressed="false" aria-label="Поставщики" title="Поставщики" class="catalog-view-button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 21h18M5 21V8l7-4 7 4v13M9 10h2m2 0h2m-6 4h2m2 0h2m-6 7v-3h6v3"/></svg>
                    <span class="sr-only">Поставщики</span>
                </button>
            </div>
            <div data-category-list class="catalog-category-strip" role="listbox" aria-label="Категории товаров">
                <button type="button" data-category="" role="option" aria-selected="true" class="catalog-category-card">
                    <span class="catalog-category-image"><x-ui.catalog-category-icon code="all" /></span>
                    <span class="catalog-category-copy"><span class="catalog-category-name">Все товары</span><span data-all-count class="catalog-category-count">— тов.</span></span>
                    <svg data-category-selected class="catalog-category-selected" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="7" fill="currentColor"/><path d="m5 8 2 2 4-4" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                @foreach($categories as $category)
                    <button type="button" data-category="{{ $category->id }}" role="option" aria-selected="false" class="catalog-category-card">
                        <span class="catalog-category-image"><x-ui.catalog-category-icon :code="$category->code" /></span>
                        <span class="catalog-category-copy"><span class="catalog-category-name">{{ $category->label }}</span><span data-category-count class="catalog-category-count">— тов.</span></span>
                        <svg data-category-selected class="catalog-category-selected" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="7" fill="currentColor"/><path d="m5 8 2 2 4-4" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                @endforeach
            </div>
        </div>
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
