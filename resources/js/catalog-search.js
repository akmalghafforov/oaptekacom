const FILTER_KEYS = ['q', 'category', 'city', 'supplier', 'form', 'min_price', 'max_price'];

export class CatalogSearchController {
    constructor({ fetcher, onState, onData, onFilters, setTimer = setTimeout, clearTimer = clearTimeout, debounceMs = 500 }) {
        this.fetcher = fetcher;
        this.onState = onState;
        this.onData = onData;
        this.onFilters = onFilters;
        this.setTimer = setTimer;
        this.clearTimer = clearTimer;
        this.debounceMs = debounceMs;
        this.filters = {};
        this.cursor = null;
        this.hasMore = false;
        this.timer = null;
        this.abortController = null;
        this.generation = 0;
        this.seenCursors = new Set();
        this.lastFailedMode = null;
        this.loading = false;
    }

    restore(filters) {
        this.filters = this.normalizeFilters(filters);
        this.resetRequest();
        this.onFilters(this.filters, false);

        if (this.filters.q?.length >= 3) {
            return this.request(false);
        }

        this.onState('waiting');
        return Promise.resolve();
    }

    setQuery(value) {
        this.filters.q = value.trim();
        this.resetRequest();
        this.onFilters(this.filters, true);

        if (this.filters.q.length < 3) {
            this.onData({ html: '', facets: [] }, false);
            this.onState('waiting');
            return;
        }

        this.onData({ html: '', facets: [] }, false);
        this.timer = this.setTimer(() => this.request(false), this.debounceMs);
    }

    setFilter(key, value) {
        if (!FILTER_KEYS.includes(key) || key === 'q') {
            return Promise.resolve();
        }

        value ? this.filters[key] = String(value).trim() : delete this.filters[key];
        this.resetRequest();
        this.onFilters(this.filters, true);
        this.onData({ html: '' }, false);

        return this.filters.q?.length >= 3 ? this.request(false) : Promise.resolve();
    }

    reset() {
        this.filters = {};
        this.resetRequest();
        this.onFilters(this.filters, true);
        this.onData({ html: '', facets: [] }, false);
        this.onState('waiting');
    }

    loadMore() {
        if (this.loading || !this.hasMore || !this.cursor || this.seenCursors.has(this.cursor)) {
            return Promise.resolve();
        }

        this.seenCursors.add(this.cursor);
        return this.request(true);
    }

    retry() {
        return this.lastFailedMode === null ? Promise.resolve() : this.request(this.lastFailedMode === 'more');
    }

    async request(loadMore) {
        const cursor = loadMore ? this.cursor : null;
        const generation = ++this.generation;
        this.abortController?.abort();
        this.abortController = new AbortController();
        this.loading = true;
        this.onState(loadMore ? 'loadingMore' : 'loading');

        try {
            const response = await this.fetcher({ ...this.filters, ...(cursor ? { cursor } : {}) }, this.abortController.signal);

            if (generation !== this.generation) {
                return;
            }

            this.cursor = response.next_cursor;
            this.hasMore = response.has_more;
            this.lastFailedMode = null;
            this.onData(response, loadMore);
            this.onState(response.html || loadMore ? (response.has_more ? 'results' : 'complete') : 'empty');
        } catch (error) {
            if (generation !== this.generation || error?.name === 'AbortError') {
                return;
            }

            if (loadMore && cursor) {
                this.seenCursors.delete(cursor);
            }
            this.lastFailedMode = loadMore ? 'more' : 'initial';
            this.onState(loadMore ? 'loadMoreError' : 'error');
        } finally {
            if (generation === this.generation) {
                this.loading = false;
            }
        }
    }

    resetRequest() {
        if (this.timer !== null) {
            this.clearTimer(this.timer);
            this.timer = null;
        }
        this.abortController?.abort();
        this.abortController = null;
        this.generation++;
        this.cursor = null;
        this.hasMore = false;
        this.loading = false;
        this.seenCursors.clear();
        this.lastFailedMode = null;
    }

    normalizeFilters(filters) {
        return Object.fromEntries(FILTER_KEYS.flatMap((key) => {
            const value = typeof filters[key] === 'string' ? filters[key].trim() : '';
            return value ? [[key, value]] : [];
        }));
    }
}

const emptyState = (title, description, retry = false) => `<div class="rounded-panel border border-dashed border-slate-300 bg-white px-5 py-10 text-center"><p class="font-semibold text-slate-700">${title}</p><p class="mt-1 text-sm text-muted">${description}</p>${retry ? '<button type="button" data-catalog-retry class="mt-4 inline-flex min-h-10 items-center rounded-control bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Повторить</button>' : ''}</div>`;

export function initializeCatalogSearch(root) {
    const form = root.querySelector('[data-catalog-form]');
    const queryInput = form.elements.q;
    const cityInput = form.elements.city;
    const results = root.querySelector('[data-catalog-results]');
    const state = root.querySelector('[data-catalog-state]');
    const region = root.querySelector('[data-catalog-region]');
    const announcer = root.querySelector('[data-catalog-announcer]');
    const loadStatus = root.querySelector('[data-catalog-load-status]');
    const categoryList = root.querySelector('[data-category-list]');
    const categoryClear = root.querySelector('[data-category-clear]');

    const controller = new CatalogSearchController({
        fetcher: async (filters, signal) => {
            const url = new URL(root.dataset.searchUrl, window.location.origin);
            Object.entries(filters).forEach(([key, value]) => url.searchParams.set(key, value));
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal });
            if (!response.ok) {
                throw new Error(`Search failed: ${response.status}`);
            }
            return response.json();
        },
        onFilters: (filters, replaceHistory) => {
            queryInput.value = filters.q ?? '';
            cityInput.value = filters.city ?? '';
            categoryClear.setAttribute('aria-pressed', filters.category ? 'false' : 'true');
            if (replaceHistory) {
                const url = new URL(window.location.href);
                FILTER_KEYS.forEach((key) => url.searchParams.delete(key));
                Object.entries(filters).forEach(([key, value]) => url.searchParams.set(key, value));
                window.history.replaceState(null, '', url);
            }
        },
        onData: (data, append) => {
            append ? results.insertAdjacentHTML('beforeend', data.html) : results.innerHTML = data.html;
            if (data.facets) {
                categoryList.innerHTML = data.facets.length ? data.facets.map((facet) => {
                    const selected = String(facet.id) === controller.filters.category;
                    return `<button type="button" data-category="${facet.id}" aria-pressed="${selected}" class="flex min-h-10 items-center justify-between gap-3 rounded-control px-3 py-2 text-left text-sm ${selected ? 'bg-brand-50 font-semibold text-brand-700' : 'hover:bg-brand-50'}"><span>${escapeHtml(facet.label)}</span><span class="text-xs text-muted">${facet.count}</span></button>`;
                }).join('') : '<p class="text-sm text-muted">Нет доступных категорий.</p>';
            }
        },
        onState: (name) => {
            region.setAttribute('aria-busy', ['loading', 'loadingMore'].includes(name) ? 'true' : 'false');
            const hasCards = results.children.length > 0;
            results.classList.toggle('hidden', !hasCards);
            state.classList.toggle('hidden', hasCards || ['results', 'complete', 'loadingMore', 'loadMoreError'].includes(name));
            loadStatus.classList.toggle('hidden', !['loadingMore', 'loadMoreError', 'complete'].includes(name));

            const messages = {
                waiting: ['Начните поиск', 'Введите не менее трёх символов названия лекарства.'],
                loading: ['Ищем предложения…', 'Подождите, загружаем актуальный каталог.'],
                empty: ['Предложений не найдено', 'Измените запрос или параметры поиска.'],
                error: ['Не удалось выполнить поиск', 'Проверьте соединение и попробуйте ещё раз.'],
            };
            if (messages[name]) {
                state.innerHTML = emptyState(messages[name][0], messages[name][1], name === 'error');
            }
            loadStatus.innerHTML = name === 'loadingMore' ? 'Загружаем ещё предложения…' : name === 'loadMoreError' ? 'Не удалось загрузить ещё. <button type="button" data-catalog-retry class="min-h-10 px-2 font-semibold text-brand-700">Повторить</button>' : name === 'complete' ? 'Все предложения загружены.' : '';
            announcer.textContent = name === 'results' ? 'Предложения загружены.' : name === 'complete' ? 'Все предложения загружены.' : messages[name]?.[0] ?? '';
        },
    });

    queryInput.addEventListener('input', () => controller.setQuery(queryInput.value));
    cityInput.addEventListener('input', () => controller.setFilter('city', cityInput.value));
    form.addEventListener('submit', (event) => event.preventDefault());
    root.querySelector('[data-catalog-reset]').addEventListener('click', () => controller.reset());
    categoryClear.addEventListener('click', () => controller.setFilter('category', ''));
    categoryList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-category]');
        if (button) {
            controller.setFilter('category', button.dataset.category);
        }
    });
    root.addEventListener('click', (event) => {
        if (event.target.closest('[data-catalog-retry]')) {
            controller.retry();
        }
    });

    if ('IntersectionObserver' in window) {
        new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                controller.loadMore();
            }
        }, { rootMargin: '300px' }).observe(root.querySelector('[data-catalog-sentinel]'));
    }

    const filtersFromUrl = () => Object.fromEntries(FILTER_KEYS.flatMap((key) => {
        const value = new URL(window.location.href).searchParams.get(key);
        return value === null ? [] : [[key, value]];
    }));
    window.addEventListener('popstate', () => controller.restore(filtersFromUrl()));
    controller.restore(filtersFromUrl());

    return controller;
}

function escapeHtml(value) {
    const element = document.createElement('span');
    element.textContent = value;
    return element.innerHTML;
}
