const FILTER_KEYS = ['q', 'category', 'sort', 'cities', 'suppliers'];
const VALID_VIEWS = ['list', 'grid', 'suppliers'];

export class CatalogSearchController {
    constructor({ fetcher, onState, onData, onFilters }) {
        this.fetcher = fetcher;
        this.onState = onState;
        this.onData = onData;
        this.onFilters = onFilters;
        this.filters = { sort: 'price_asc' };
        this.view = 'list';
        this.cursor = null;
        this.hasMore = false;
        this.loading = false;
        this.abortController = null;
        this.generation = 0;
        this.lastFailedMode = null;
    }

    restore(filters, view = this.view) {
        this.filters = this.normalizeFilters(filters);
        this.view = VALID_VIEWS.includes(view) ? view : 'list';
        this.resetRequest();
        this.onFilters(this.filters, false);
        return this.request(false);
    }

    setDraftQuery(value) {
        this.filters.q = value.trim();
        if (!this.filters.q) delete this.filters.q;
        this.onFilters(this.filters, false);
    }

    submit(query = this.filters.q ?? '') {
        this.setDraftQuery(query);
        if (this.filters.q && this.filters.q.length < 3) {
            this.onState('invalid');
            return Promise.resolve(false);
        }
        return this.commit();
    }

    setFilter(key, value) {
        if (!FILTER_KEYS.includes(key) || key === 'q') return Promise.resolve();
        const normalized = Array.isArray(value) ? value.map(String).filter(Boolean) : String(value ?? '').trim();
        normalized.length ? this.filters[key] = normalized : delete this.filters[key];
        return this.commit();
    }

    setFilters(filters) {
        this.filters = this.normalizeFilters({ ...this.filters, ...filters });
        return this.commit();
    }

    setView(view) {
        if (!VALID_VIEWS.includes(view) || view === this.view) return Promise.resolve();
        this.view = view;
        return this.commit(false);
    }

    commit(pushHistory = true) {
        this.resetRequest();
        this.onFilters(this.filters, pushHistory);
        this.onData({}, false);
        return this.request(false);
    }

    loadMore() {
        if (this.loading || !this.hasMore || !this.cursor) return Promise.resolve();
        return this.request(true);
    }

    retry() {
        return this.lastFailedMode === null ? Promise.resolve() : this.request(this.lastFailedMode === 'more');
    }

    async request(append) {
        const generation = ++this.generation;
        this.abortController?.abort();
        this.abortController = new AbortController();
        this.loading = true;
        this.onState(append ? 'loadingMore' : 'loading');
        try {
            const response = await this.fetcher({ ...this.filters, view: this.view, ...(append && this.cursor ? { cursor: this.cursor } : {}) }, this.abortController.signal);
            if (generation !== this.generation) return;
            const pagination = response.pagination ?? response;
            this.cursor = pagination.next_cursor;
            this.hasMore = Boolean(pagination.has_more);
            this.lastFailedMode = null;
            this.onData(response, append);
            const html = response.fragments?.cards ?? response.html ?? '';
            this.onState(html || append ? (this.hasMore ? 'results' : 'complete') : 'empty');
        } catch (error) {
            if (generation !== this.generation || error?.name === 'AbortError') return;
            this.lastFailedMode = append ? 'more' : 'initial';
            this.onState(append ? 'loadMoreError' : 'error');
        } finally {
            if (generation === this.generation) this.loading = false;
        }
    }

    resetRequest() {
        this.abortController?.abort();
        this.abortController = null;
        this.generation++;
        this.cursor = null;
        this.hasMore = false;
        this.loading = false;
        this.lastFailedMode = null;
    }

    normalizeFilters(filters) {
        const output = {};
        FILTER_KEYS.forEach((key) => {
            const value = filters[key];
            if (Array.isArray(value)) {
                const items = value.map(String).map((item) => item.trim()).filter(Boolean);
                if (items.length) output[key] = [...new Set(items)];
            } else if (typeof value === 'string' && value.trim()) {
                output[key] = value.trim();
            }
        });
        output.sort ??= 'price_asc';
        return output;
    }
}

const emptyState = (title, description, retry = false) => `<div class="rounded-panel border border-dashed border-slate-300 bg-white px-5 py-10 text-center"><p class="font-semibold text-slate-700">${title}</p><p class="mt-1 text-sm text-muted">${description}</p>${retry ? '<button type="button" data-catalog-retry class="mt-4 min-h-10 rounded-control bg-brand-600 px-4 py-2 font-semibold text-white">Повторить</button>' : ''}</div>`;

export function initializeCatalogSearch(root) {
    const form = root.querySelector('[data-catalog-form]');
    const queryInput = form.elements.q;
    const clearButton = root.querySelector('[data-search-clear]');
    const region = root.querySelector('[data-catalog-region]');
    const state = root.querySelector('[data-catalog-state]');
    const announcer = root.querySelector('[data-catalog-announcer]');
    const loadMore = root.querySelector('[data-load-more]');
    const loadStatus = root.querySelector('[data-catalog-load-status]');
    const dialog = root.querySelector('[data-dialog="catalog-filters"]');
    const filterOpen = root.querySelector('[data-filter-open]');
    let appliedModal = { sort: 'price_asc', cities: [], suppliers: [] };
    let lastProductView = 'list';

    const controller = new CatalogSearchController({
        fetcher: async (filters, signal) => {
            const url = new URL(root.dataset.searchUrl, window.location.origin);
            Object.entries(filters).forEach(([key, value]) => Array.isArray(value) ? value.forEach((item) => url.searchParams.append(`${key}[]`, item)) : url.searchParams.set(key, value));
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal });
            if (!response.ok) throw new Error(`Search failed: ${response.status}`);
            return response.json();
        },
        onFilters: (filters, pushHistory) => {
            queryInput.value = filters.q ?? '';
            toggleClear();
            root.querySelectorAll('[data-category]').forEach((button) => button.setAttribute('aria-selected', String(button.dataset.category) === String(filters.category ?? '') ? 'true' : 'false'));
            if (pushHistory) updateHistory(filters, 'pushState');
        },
        onData: (data, append) => renderData(data, append),
        onState: (name) => renderState(name),
    });

    const containers = {
        list: root.querySelector('[data-list-view]'), grid: root.querySelector('[data-grid-view]'), suppliers: root.querySelector('[data-suppliers-view]'),
    };
    const renderData = (data, append) => {
        const fragments = data.fragments ?? {};
        const cards = fragments.cards ?? data.html ?? '';
        const target = controller.view === 'grid' ? root.querySelector('[data-catalog-grid]') : controller.view === 'suppliers' ? root.querySelector('[data-catalog-suppliers]') : root.querySelector('[data-catalog-cards]');
        const html = controller.view === 'suppliers' ? (fragments.supplier_cards ?? '') : cards;
        if (append) target.insertAdjacentHTML('beforeend', html); else target.innerHTML = html;
        if (controller.view === 'list') {
            const table = root.querySelector('[data-catalog-table]');
            if (append) table.insertAdjacentHTML('beforeend', fragments.desktop_rows ?? ''); else table.innerHTML = fragments.desktop_rows ?? '';
        }
        if (data.facets) updateFacets(data.facets);
        root.querySelector('[data-result-count]').textContent = data.pagination ? `${data.pagination.total} предложений` : '';
        Object.entries(containers).forEach(([view, element]) => element.classList.toggle('hidden', view !== controller.view));
    };
    const renderState = (name) => {
        region.setAttribute('aria-busy', ['loading', 'loadingMore'].includes(name) ? 'true' : 'false');
        const messages = { loading: ['Ищем предложения…', 'Загружаем актуальный каталог.'], invalid: ['Уточните запрос', 'Введите минимум 3 символа или очистите поле.'], empty: ['Предложений не найдено', 'Измените запрос или фильтры.'], error: ['Не удалось выполнить поиск', 'Проверьте соединение и повторите попытку.'] };
        state.innerHTML = messages[name] ? emptyState(...messages[name], name === 'error') : '';
        loadMore.classList.toggle('hidden', !controller.hasMore || name === 'loadingMore');
        loadStatus.textContent = name === 'loadingMore' ? 'Загружаем…' : name === 'complete' ? 'Все предложения загружены.' : name === 'loadMoreError' ? 'Не удалось загрузить следующую страницу.' : '';
        announcer.textContent = messages[name]?.[0] ?? (name === 'complete' ? 'Все предложения загружены.' : 'Предложения загружены.');
    };
    const updateFacets = (facets) => {
        root.querySelector('[data-all-count]').textContent = `${facets.all_count} тов.`;
        const counts = new Map((facets.categories ?? []).map((item) => [String(item.id), item.count]));
        root.querySelectorAll('[data-category]').forEach((button) => { const count = button.querySelector('[data-category-count]'); if (count) count.textContent = `${counts.get(button.dataset.category) ?? 0} тов.`; });
    };
    const toggleClear = () => { clearButton.hidden = !queryInput.value; clearButton.classList.toggle('hidden', !queryInput.value); clearButton.classList.toggle('grid', Boolean(queryInput.value)); };
    const updateHistory = (filters, method) => {
        const url = new URL(window.location.href);
        [...url.searchParams.keys()].filter((key) => FILTER_KEYS.includes(key.replace('[]', ''))).forEach((key) => url.searchParams.delete(key));
        Object.entries(filters).forEach(([key, value]) => { if (key === 'sort' && value === 'price_asc') return; Array.isArray(value) ? value.forEach((item) => url.searchParams.append(`${key}[]`, item)) : url.searchParams.set(key, value); });
        window.history[method](null, '', url);
    };
    const filtersFromUrl = () => { const url = new URL(window.location.href); return { q: url.searchParams.get('q') ?? '', category: url.searchParams.get('category') ?? '', sort: url.searchParams.get('sort') ?? 'price_asc', cities: url.searchParams.getAll('cities[]'), suppliers: url.searchParams.getAll('suppliers[]') }; };

    form.addEventListener('submit', (event) => { event.preventDefault(); controller.submit(queryInput.value); });
    queryInput.addEventListener('input', toggleClear);
    clearButton.addEventListener('click', () => { queryInput.value = ''; queryInput.focus(); toggleClear(); });
    loadMore.addEventListener('click', () => controller.loadMore());
    root.addEventListener('click', (event) => {
        const category = event.target.closest('[data-category]'); if (category) controller.setFilter('category', category.dataset.category);
        const view = event.target.closest('[data-view]'); if (view) { if (view.dataset.view !== 'suppliers') lastProductView = view.dataset.view; localStorage.setItem('oapteka.catalog.view.v1', view.dataset.view); root.querySelectorAll('[data-view]').forEach((button) => button.setAttribute('aria-pressed', button === view ? 'true' : 'false')); controller.setView(view.dataset.view); }
        const supplier = event.target.closest('[data-supplier-open]'); if (supplier) { controller.view = lastProductView; controller.setFilter('suppliers', [supplier.dataset.supplierOpen]); }
        if (event.target.closest('[data-catalog-retry]')) controller.retry();
    });
    root.querySelector('[data-category-list]').addEventListener('keydown', (event) => { const buttons = [...event.currentTarget.querySelectorAll('[data-category]')]; const index = buttons.indexOf(document.activeElement); const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : event.key === 'ArrowRight' ? Math.min(index + 1, buttons.length - 1) : event.key === 'ArrowLeft' ? Math.max(index - 1, 0) : null; if (next !== null) { event.preventDefault(); buttons[next].focus(); } });

    const syncDialog = () => { dialog.querySelector(`[name="filter_sort"][value="${appliedModal.sort}"]`)?.click(); ['cities', 'suppliers'].forEach((key) => dialog.querySelectorAll(`[name="filter_${key}[]"]`).forEach((input) => { input.checked = appliedModal[key].includes(input.value); })); };
    filterOpen.addEventListener('click', () => { appliedModal = { sort: controller.filters.sort ?? 'price_asc', cities: controller.filters.cities ?? [], suppliers: controller.filters.suppliers ?? [] }; syncDialog(); dialog.showModal(); document.body.classList.add('dialog-open'); });
    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('close', () => { document.body.classList.remove('dialog-open'); filterOpen.focus(); });
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
    dialog.querySelectorAll('[data-filter-section]').forEach((section) => { const checks = [...section.querySelectorAll('[data-option-label] input')]; section.querySelector('[data-select-all]').addEventListener('change', (event) => checks.filter((input) => !input.closest('[data-option-label]').hidden).forEach((input) => { input.checked = event.target.checked; })); section.querySelector('[data-section-clear]').addEventListener('click', () => checks.forEach((input) => { input.checked = false; })); section.querySelector('[data-option-search]').addEventListener('input', (event) => section.querySelectorAll('[data-option-label]').forEach((label) => { label.hidden = !label.textContent.toLocaleLowerCase('ru').includes(event.target.value.toLocaleLowerCase('ru')); })); });
    dialog.querySelector('[data-filter-reset]').addEventListener('click', () => { dialog.querySelector('[name="filter_sort"][value="price_asc"]').checked = true; dialog.querySelectorAll('[data-option-label] input').forEach((input) => { input.checked = false; }); });
    dialog.querySelector('[data-filter-apply]').addEventListener('click', () => { const values = (name) => [...dialog.querySelectorAll(`[name="filter_${name}[]"]:checked`)].map((input) => input.value); const filters = { sort: dialog.querySelector('[name="filter_sort"]:checked').value, cities: values('cities'), suppliers: values('suppliers') }; const count = filters.cities.length + filters.suppliers.length + (filters.sort === 'price_asc' ? 0 : 1); const badge = root.querySelector('[data-filter-count]'); badge.textContent = count; badge.classList.toggle('hidden', count === 0); dialog.close(); controller.setFilters(filters); });

    root.addEventListener('submit', async (event) => { const cartForm = event.target.closest('[data-cart-form]'); if (!cartForm) return; event.preventDefault(); const button = cartForm.querySelector('button'); button.disabled = true; try { const response = await fetch(cartForm.action, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': cartForm.querySelector('[name="_token"]').value, 'Content-Type': 'application/json' }, body: JSON.stringify({ quantity: Number(cartForm.elements.quantity.value) }) }); const data = await response.json(); if (!response.ok) throw new Error(data.message); root.querySelectorAll('[data-cart-badge]').forEach((badge) => { badge.textContent = data.cart.total_quantity; }); announcer.textContent = data.message; button.textContent = 'Добавлено'; } catch { announcer.textContent = 'Не удалось добавить товар в корзину.'; } finally { button.disabled = false; } });

    if ('IntersectionObserver' in window) new IntersectionObserver((entries) => { const compact = !entries[0].isIntersecting; const panel = root.querySelector('[data-search-panel]'); if (!compact || !panel.contains(document.activeElement)) panel.classList.toggle('is-compact', compact); }, { rootMargin: '-72px 0px 0px' }).observe(root.querySelector('[data-search-sentinel]'));
    window.addEventListener('popstate', () => controller.restore(filtersFromUrl(), controller.view));
    const storedView = localStorage.getItem('oapteka.catalog.view.v1'); controller.view = VALID_VIEWS.includes(storedView) ? storedView : 'list'; root.querySelectorAll('[data-view]').forEach((button) => button.setAttribute('aria-pressed', button.dataset.view === controller.view ? 'true' : 'false')); updateHistory(filtersFromUrl(), 'replaceState'); controller.restore(filtersFromUrl(), controller.view);
    return controller;
}
