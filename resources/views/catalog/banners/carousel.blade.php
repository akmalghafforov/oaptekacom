<x-ui.card data-banner-carousel data-next-url="{{ route('catalog.banners.next') }}" class="mb-5" :hidden="filled(trim((string) request('q', '')))" role="region" aria-roledescription="карусель" aria-label="Рекламные предложения">
    <div class="mb-3 flex items-center justify-between gap-3"><p class="text-xs text-muted">Реклама</p><span data-banner-position class="text-sm text-muted" aria-live="polite"></span></div>
    <a data-banner-link hidden class="block overflow-hidden rounded-control focus-visible:outline-2 focus-visible:outline-offset-2" aria-label="">
        <picture>
            <source data-banner-mobile media="(max-width: 639px)">
            <source data-banner-tablet media="(max-width: 1023px)">
            <img data-banner-image alt="" class="aspect-[4/3] w-full object-cover sm:aspect-[2/1] lg:aspect-[3/1]">
        </picture>
    </a>
    <p data-banner-loading class="py-8 text-center text-muted" role="status">Загружаем предложения…</p>
    <template data-banner-indicator-template><x-ui.button type="button" variant="secondary" class="min-w-10 px-3"></x-ui.button></template>
    <div data-banner-controls hidden class="mt-4 flex flex-wrap items-center justify-center gap-3">
        <x-ui.button type="button" variant="secondary" data-banner-previous aria-label="Предыдущее предложение">←</x-ui.button>
        <div data-banner-indicators class="flex flex-wrap justify-center gap-2" role="group" aria-label="Позиции баннеров"></div>
        <x-ui.button type="button" variant="secondary" data-banner-next aria-label="Следующее предложение">→</x-ui.button>
        <x-ui.button type="button" variant="ghost" data-banner-pause aria-pressed="false">Приостановить</x-ui.button>
    </div>
</x-ui.card>
<noscript><p class="mb-4 text-sm text-muted">Для просмотра рекламных предложений включите JavaScript. Поиск доступен через кнопку «Найти».</p></noscript>
