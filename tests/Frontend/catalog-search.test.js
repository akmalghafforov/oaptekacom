import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeCategoryPicker, activeCategoryLabel, catalogFilterChips, renderCatalogFilterChips, CatalogSearchController, catalogFragment, updateCartBadges, updateCatalogCategories, updateCatalogOfferCartState } from '../../resources/js/catalog-search.js';

const deferred = () => { let resolve; const promise = new Promise((done) => { resolve = done; }); return { promise, resolve }; };
const harness = (fetcher = async () => ({ fragments: { cards: '<article />' }, pagination: { next_cursor: null, has_more: false } })) => {
    const states = []; const data = []; const filters = [];
    const controller = new CatalogSearchController({ fetcher, onState: (value) => states.push(value), onData: (value, append) => data.push([value, append]), onFilters: (value, push) => filters.push([{ ...value }, push]) });
    return { controller, states, data, filters };
};

test('typing changes only the draft and submit performs the request', async () => {
    let requests = 0;
    const { controller, states } = harness(async () => { requests++; return { fragments: { cards: '' }, pagination: { next_cursor: null, has_more: false } }; });
    controller.setDraftQuery('аспирин');
    assert.equal(requests, 0);
    await controller.submit('аспирин');
    assert.equal(requests, 1);
    assert.equal(states.at(-1), 'empty');
});

test('empty query browses while short non-empty query is rejected', async () => {
    let requested;
    const { controller, states } = harness(async (filters) => { requested = filters; return { fragments: { cards: '' }, pagination: { next_cursor: null, has_more: false } }; });
    await controller.submit('аб');
    assert.equal(states.at(-1), 'invalid');
    await controller.submit('');
    assert.equal(requested.view, 'list');
    assert.equal('q' in requested, false);
});

test('committed filters use arrays and reset pagination', async () => {
    const requests = [];
    const { controller, filters } = harness(async (input) => { requests.push(input); return { fragments: { cards: '' }, pagination: { next_cursor: null, has_more: false } }; });
    controller.cursor = 'old';
    await controller.setFilters({ cities: ['Душанбе'], suppliers: ['4'], sort: 'updated_desc' });
    assert.deepEqual(requests[0].cities, ['Душанбе']);
    assert.equal(controller.cursor, null);
    assert.equal(filters.at(-1)[1], true);
});

test('category selection updates its label without changing the search request contract', async () => {
    let requested;
    const { controller } = harness(async (input) => { requested = input; return { fragments: { cards: '' }, pagination: { next_cursor: null, has_more: false } }; });
    const categories = [{ value: '', label: 'Все товары' }, { value: '12', label: 'Таблетки' }];

    await controller.setFilter('category', '12');

    assert.equal(activeCategoryLabel(categories, controller.filters.category), 'Таблетки');
    assert.equal(requested.category, '12');
    assert.equal(requested.view, 'list');
});

test('late responses are ignored after a newer committed search', async () => {
    const first = deferred(); const second = deferred(); let index = 0;
    const { controller, data } = harness(() => [first, second][index++].promise);
    const oldRequest = controller.submit('первый');
    const newRequest = controller.submit('второй');
    second.resolve({ fragments: { cards: 'second' }, pagination: { next_cursor: null, has_more: false } }); await newRequest;
    first.resolve({ fragments: { cards: 'first' }, pagination: { next_cursor: null, has_more: false } }); await oldRequest;
    assert.deepEqual(data.map(([response]) => response.fragments?.cards).filter(Boolean), ['second']);
});

test('load more uses one explicit cursor request', async () => {
    const requests = [];
    const { controller, data } = harness(async (input) => { requests.push(input); return requests.length === 1 ? { fragments: { cards: 'first' }, pagination: { next_cursor: 'next', has_more: true } } : { fragments: { cards: 'more' }, pagination: { next_cursor: null, has_more: false } }; });
    await controller.submit('аспирин');
    await controller.loadMore();
    assert.equal(requests[1].cursor, 'next');
    assert.equal(data.at(-1)[1], true);
});

test('view changes are requested but excluded from shareable filters', async () => {
    let requested;
    const { controller, filters } = harness(async (input) => { requested = input; return { fragments: { cards: '' }, pagination: { next_cursor: null, has_more: false } }; });
    await controller.setView('grid');
    assert.equal(requested.view, 'grid');
    assert.equal('view' in filters.at(-1)[0], false);
});

test('request reports mode-specific loading before results', async () => {
    const request = deferred();
    const { controller, states } = harness(() => request.promise);

    const pending = controller.setView('suppliers');
    assert.equal(states.at(-1), 'loading');
    request.resolve({ fragments: { supplier_cards: '<article />' }, pagination: { next_cursor: null, has_more: false } });
    await pending;

    assert.equal(states.at(-1), 'complete');
});

test('fragment selection separates mobile rows from tablet cards and desktop rows', () => {
    const response = { html: 'legacy', fragments: { mobile_rows: 'mobile', desktop_rows: 'desktop', cards: 'cards', supplier_cards: 'suppliers' } };

    assert.equal(catalogFragment(response, 'list', 'mobile'), 'mobile');
    assert.equal(catalogFragment(response, 'list'), 'cards');
    assert.equal(catalogFragment(response, 'list', 'desktop'), 'desktop');
    assert.equal(catalogFragment(response, 'grid'), 'cards');
    assert.equal(catalogFragment(response, 'suppliers'), 'suppliers');
});

test('cart responses persistently synchronize every rendered offer state and basket badge', () => {
    const classList = () => ({ hidden: false, toggle(_name, hidden) { this.hidden = hidden; } });
    const addInput = { disabled: false };
    const addButton = { disabled: false };
    const quantity = { textContent: '1' };
    const removeButton = { disabled: true };
    const removeForm = { action: '', querySelectorAll: (selector) => selector === 'button' ? [removeButton] : [] };
    const addControls = { classList: classList(), querySelectorAll: () => [addInput, addButton] };
    const inCartControls = {
        classList: classList(),
        querySelectorAll: (selector) => selector === '[data-cart-quantity]' ? [quantity] : [],
        querySelector: (selector) => selector === '[data-cart-remove-form]' ? removeForm : null,
    };
    const offer = { querySelector: (selector) => selector === '[data-cart-add-controls]' ? addControls : inCartControls };
    const root = { querySelectorAll: (selector) => selector === '[data-offer-id="42"]' ? [offer, offer] : [] };
    const badge = { textContent: '', classList: classList() };
    const documentRoot = { querySelectorAll: () => [badge] };

    updateCatalogOfferCartState(root, { offer_id: 42, quantity: 3, remove_url: '/cart/items/9' });
    updateCartBadges(documentRoot, 3);

    assert.equal(addControls.classList.hidden, true);
    assert.equal(inCartControls.classList.hidden, false);
    assert.equal(addInput.disabled, true);
    assert.equal(quantity.textContent, 3);
    assert.equal(removeForm.action, '/cart/items/9');
    assert.equal(badge.textContent, 3);

    updateCatalogOfferCartState(root, { offer_id: 42 });
    updateCartBadges(documentRoot, 0);

    assert.equal(addControls.classList.hidden, false);
    assert.equal(inCartControls.classList.hidden, true);
    assert.equal(addInput.disabled, false);
    assert.equal(removeButton.disabled, true);
    assert.equal(badge.classList.hidden, true);
});

const categoryHarness = () => {
    const ownerDocument = { activeElement: null };
    const initialCategories = ['', 'syrup', 'zero', 'tablets', 'tie'].map((id) => ({
        dataset: { category: id },
        count: { textContent: '' },
        selected: id === 'syrup',
        ownerDocument,
        querySelector() { return this.dataset.category ? this.count : null; },
        focus() { ownerDocument.activeElement = this; },
    }));
    const categoryList = {
        children: [...initialCategories],
        appendChild(button) {
            this.children.splice(this.children.indexOf(button), 1);
            this.children.push(button);
            if (ownerDocument.activeElement === button) ownerDocument.activeElement = null;
        },
    };
    return { initialCategories, categoryList, ownerDocument, order: () => categoryList.children.map((button) => button.dataset.category) };
};

test('category cards show descending counts with all products first and missing categories last', () => {
    const { categoryList, initialCategories, order } = categoryHarness();

    updateCatalogCategories(categoryList, initialCategories, { categories: [{ id: 'syrup', count: 4 }, { id: 'tablets', count: 6 }] });

    assert.deepEqual(order(), ['', 'tablets', 'syrup', 'zero', 'tie']);
    assert.equal(initialCategories[1].count.textContent, '4 тов.');
    assert.equal(initialCategories[2].count.textContent, '0 тов.');
    assert.equal(initialCategories[3].count.textContent, '6 тов.');
});

test('new searches restore initial order for ties and preserve selected and focused category nodes', () => {
    const { categoryList, initialCategories, ownerDocument, order } = categoryHarness();
    const selected = initialCategories[1];
    selected.focus();
    updateCatalogCategories(categoryList, initialCategories, { categories: [{ id: 'tablets', count: 6 }, { id: 'tie', count: 4 }] });

    updateCatalogCategories(categoryList, initialCategories, { categories: [{ id: 'tablets', count: 4 }, { id: 'syrup', count: 4 }] });

    assert.deepEqual(order(), ['', 'syrup', 'tablets', 'zero', 'tie']);
    assert.equal(categoryList.children[1], selected);
    assert.equal(selected.selected, true);
    assert.equal(ownerDocument.activeElement, selected);
});

test('load more without facets preserves category order and counts', async () => {
    const { categoryList, initialCategories, order } = categoryHarness();
    const controller = new CatalogSearchController({
        fetcher: async (filters) => filters.cursor
            ? { fragments: { cards: 'more' }, facets: null, pagination: { next_cursor: null, has_more: false } }
            : { fragments: { cards: 'first' }, facets: { categories: [{ id: 'tablets', count: 6 }, { id: 'syrup', count: 4 }] }, pagination: { next_cursor: 'next', has_more: true } },
        onState: () => {},
        onFilters: () => {},
        onData: (data) => { if (data.facets) updateCatalogCategories(categoryList, initialCategories, data.facets); },
    });
    await controller.submit('аспирин');

    await controller.loadMore();

    assert.deepEqual(order(), ['', 'tablets', 'syrup', 'zero', 'tie']);
    assert.equal(initialCategories[3].count.textContent, '6 тов.');
});


const chipLabels = {
    suppliers: new Map([['4', 'Ёсин-М'], ['5', '<b>Длинное название поставщика</b>']]),
    cities: new Map([['Худжанд', 'Худжанд']]),
    category: new Map([['12', 'Таблетки']]),
    sort: new Map([['updated_desc', 'Сначала новые']]),
};

test('chips label every selection, exclude query and default sort, and provide ID fallbacks', () => {
    const chips = catalogFilterChips({ q: 'аспирин', suppliers: ['4', '99'], cities: ['Худжанд'], category: '12', sort: 'updated_desc' }, chipLabels);

    assert.deepEqual(chips.map((chip) => chip.label), ['Поставщик: Ёсин-М', 'Поставщик: ID 99', 'Город: Худжанд', 'Категория: Таблетки', 'Сортировка: Сначала новые']);
    assert.deepEqual(catalogFilterChips({ q: 'аспирин', sort: 'price_asc' }), []);
    assert.equal(catalogFilterChips({ category: '99' })[0].label, 'Категория: ID 99');
});

for (const [key, value, remaining] of [
    ['suppliers', '4', ['5']], ['cities', 'Худжанд', ['Душанбе']], ['category', '12', undefined], ['sort', 'updated_desc', 'price_asc'],
]) {
    test(`removing ${key} preserves query, view and other filters and resets pagination`, async () => {
        const requests = [];
        const { controller, filters } = harness(async (input) => { requests.push(input); return { fragments: {}, pagination: { next_cursor: null, has_more: false } }; });
        const original = { q: 'аспирин', suppliers: ['4', '5'], cities: ['Худжанд', 'Душанбе'], category: '12', sort: 'updated_desc' };
        await controller.restore(original, 'grid');
        controller.cursor = 'old-page';
        controller.hasMore = true;

        await controller.removeFilter(key, value);

        const expected = { ...original, [key]: remaining };
        if (remaining === undefined) delete expected[key];
        assert.deepEqual(controller.filters, expected);
        assert.deepEqual(requests.at(-1), { ...expected, view: 'grid' });
        assert.equal(filters.at(-1)[1], true);
        assert.equal(controller.cursor, null);
    });
}

const chipRenderHarness = () => {
    const ownerDocument = { activeElement: null };
    const makeButton = () => ({
        ownerDocument, dataset: {}, label: { textContent: '' }, attributes: {},
        querySelector() { return this.label; },
        setAttribute(key, value) { this.attributes[key] = value; },
        focus() { ownerDocument.activeElement = this; },
    });
    const container = { children: [], replaceChildren(...children) { this.children = children; } };
    const row = { hidden: true };
    const badge = { textContent: '', classList: { toggle(name, value) { this[name] = value; } } };
    const filterOpen = makeButton();
    const elements = {
        '[data-filter-chips]': container,
        '[data-filter-chip-template]': { content: { firstElementChild: { cloneNode: makeButton } } },
        '[data-active-filters]': row, '[data-filter-count]': badge, '[data-filter-open]': filterOpen,
    };
    return { root: { querySelector: (selector) => elements[selector] }, container, row, badge, filterOpen, ownerDocument };
};

test('chip rendering uses literal text, descriptive labels and moves focus next, previous, then to filters', () => {
    const { root, container, row, badge, filterOpen, ownerDocument } = chipRenderHarness();
    const chips = catalogFilterChips({ suppliers: ['4', '5'], cities: ['Худжанд'] }, chipLabels);
    renderCatalogFilterChips(root, chips);
    assert.equal(badge.textContent, 3);
    assert.equal(row.hidden, false);
    assert.equal(container.children[1].label.textContent, 'Поставщик: <b>Длинное название поставщика</b>');
    assert.equal(container.children[0].attributes['aria-label'], 'Удалить фильтр «Поставщик: Ёсин-М»');

    container.children[0].focus();
    renderCatalogFilterChips(root, chips.slice(1));
    assert.equal(ownerDocument.activeElement, container.children[0]);
    container.children[1].focus();
    renderCatalogFilterChips(root, chips.slice(1, 2));
    assert.equal(ownerDocument.activeElement, container.children[0]);
    renderCatalogFilterChips(root, []);
    assert.equal(ownerDocument.activeElement, filterOpen);
    assert.equal(row.hidden, true);
    assert.equal(badge.classList.hidden, true);
});

test('restored filters synchronize chips and badge even during loading, empty results and errors', async () => {
    const { root, container, badge, row } = chipRenderHarness();
    const pending = deferred();
    let fail = false;
    const controller = new CatalogSearchController({
        fetcher: async () => { if (fail) throw new Error('offline'); return pending.promise; },
        onFilters: (filters) => renderCatalogFilterChips(root, catalogFilterChips(filters, chipLabels)),
        onData: () => {}, onState: () => {},
    });
    const request = controller.restore({ suppliers: ['4'], category: '12' });
    assert.equal(badge.textContent, 2);
    pending.resolve({ fragments: {}, pagination: {} });
    await request;
    assert.equal(row.hidden, false);
    fail = true;
    await controller.restore({ cities: ['Худжанд'] });
    assert.equal(badge.textContent, 1);
    assert.equal(container.children[0].label.textContent, 'Город: Худжанд');
    await controller.restore({});
    assert.equal(row.hidden, true);
    assert.equal(badge.textContent, 0);
});

const pickerHarness = (controller) => {
    const element = (dataset = {}) => ({ dataset, attributes: {}, listeners: {}, textContent: '', setAttribute(key, value) { this.attributes[key] = value; }, addEventListener(key, fn) { this.listeners[key] = fn; }, focus() { documentRoot.activeElement = this; }, click() { this.listeners.click({ target: this }); } });
    const documentRoot = { activeElement: null, body: { classList: { locked: false, add() { this.locked = true; }, remove() { this.locked = false; } } }, querySelector() { return dialog.open || this.otherModal ? dialog : null; } };
    const rows = ['', '12', '13'].map((value) => { const row = element({ mobileCategory: value }); row.label = { textContent: value ? `Категория ${value}` : 'Все категории' }; row.count = { textContent: '— тов.' }; row.icon = { src: `icon-${value || 'all'}.webp` }; row.querySelector = (selector) => selector.includes('count') ? row.count : selector.includes('image') ? row.icon : row.label; return row; });
    const trigger = element(); trigger.label = element(); trigger.count = element(); trigger.icon = {}; trigger.querySelector = (selector) => selector.includes('count') ? trigger.count : selector.includes('icon') ? trigger.icon : trigger.label;
    const close = element();
    const dialog = element(); dialog.open = false; dialog.showModal = () => { dialog.open = true; }; dialog.close = () => { dialog.open = false; dialog.listeners.close(); }; dialog.querySelectorAll = () => rows; const options = { children: [...rows], appendChild(row) { this.children = this.children.filter((item) => item !== row); this.children.push(row); } }; dialog.querySelector = (selector) => selector.includes('options') ? options : close;
    const desktopRow = element({ category: '12' });
    const desktop = element(); desktop.matches = false;
    const root = { ownerDocument: documentRoot, querySelector: (selector) => selector.includes('picker-open') ? trigger : dialog, querySelectorAll: () => [desktopRow] };
    const sync = initializeCategoryPicker(root, controller, desktop);
    return { sync, trigger, dialog, close, rows, options, desktop, desktopRow, documentRoot };
};

test('picker restores selection, closes without refetching current choice, and preserves filters when clearing', async () => {
    const requests = [];
    const { controller } = harness(async (input) => { requests.push(input); return { fragments: {}, pagination: {} }; });
    const picker = pickerHarness(controller);
    const original = { q: 'кетоти', category: '12', cities: ['Душанбе'], suppliers: ['4', '5'], sort: 'updated_desc' };
    controller.onFilters = picker.sync;
    await controller.restore(original, 'grid');
    assert.equal(picker.trigger.label.textContent, 'Категория 12');
    assert.equal(picker.rows[1].attributes['aria-pressed'], 'true');
    picker.trigger.click();
    assert.equal(picker.documentRoot.activeElement, picker.rows[1]);
    assert.equal(picker.trigger.attributes['aria-expanded'], 'true');
    picker.rows[1].click();
    assert.equal(requests.length, 1);
    assert.equal(picker.documentRoot.activeElement, picker.trigger);
    controller.cursor = 'old';
    picker.trigger.click(); picker.rows[0].click();
    assert.deepEqual(requests.at(-1), { q: 'кетоти', cities: ['Душанбе'], suppliers: ['4', '5'], sort: 'updated_desc', view: 'grid' });
    assert.equal(controller.cursor, null);
    assert.equal(picker.trigger.label.textContent, 'Все категории');
    await controller.restore(original, controller.view);
    assert.equal(picker.trigger.label.textContent, 'Категория 12');
    await controller.restore({ ...original, category: '13' }, controller.view);
    assert.equal(picker.rows[2].attributes['aria-pressed'], 'true');
});

test('picker dismissal retains filters and scroll lock for other modals, and desktop transition restores desktop focus', () => {
    const { controller } = harness(); controller.filters.category = '12';
    const picker = pickerHarness(controller);
    picker.trigger.click(); picker.close.click();
    assert.equal(controller.filters.category, '12');
    assert.equal(picker.documentRoot.body.classList.locked, false);
    picker.trigger.click(); picker.documentRoot.otherModal = true;
    picker.dialog.listeners.click({ target: picker.dialog });
    assert.equal(picker.documentRoot.body.classList.locked, true);
    picker.documentRoot.otherModal = false;
    picker.trigger.click(); picker.desktop.matches = true; picker.desktop.listeners.change();
    assert.equal(picker.dialog.open, false);
    assert.equal(picker.documentRoot.activeElement, picker.desktopRow);
});

test('category requests ignore stale responses and retain the selected category on failure for retry', async () => {
    const old = deferred(); let count = 0;
    const { controller, data, states } = harness(async () => { if (++count === 1) return old.promise; if (count === 2) throw new Error('offline'); return { fragments: { cards: 'retry' }, pagination: {} }; });
    const pending = controller.setFilter('category', '12');
    await controller.setFilter('category', '13');
    old.resolve({ fragments: { cards: 'stale' }, pagination: {} }); await pending;
    assert.equal(controller.filters.category, '13');
    assert.equal(states.at(-1), 'error');
    assert.equal(data.some(([response]) => response.fragments?.cards === 'stale'), false);
    await controller.retry();
    assert.equal(data.at(-1)[0].fragments.cards, 'retry');
});


test('picker displays original selected icons and facet counts', () => {
    const { controller } = harness();
    const picker = pickerHarness(controller);
    picker.sync({ category: '12' }, { all_count: 9, categories: [{ id: 12, count: 4 }] });
    assert.equal(picker.trigger.icon.src, 'icon-12.webp');
    assert.equal(picker.trigger.count.textContent, '4 тов.');
    assert.equal(picker.rows[0].count.textContent, '9 тов.');
    assert.equal(picker.rows[2].count.textContent, '0 тов.');
    picker.sync({});
    assert.equal(picker.trigger.icon.src, 'icon-all.webp');
    assert.equal(picker.trigger.count.textContent, '9 тов.');
    assert.deepEqual(picker.rows.map((row) => row.dataset.mobileCategory), ['', '12', '13']);
});


test('picker hides zero matches, sorts descending, restores ties and keeps hidden selection clearable', () => {
    const { controller } = harness(); controller.filters.category = '12';
    const picker = pickerHarness(controller);
    picker.sync(controller.filters, { all_count: 9, categories: [{ id: 13, count: 9 }] });
    assert.equal(picker.rows[1].hidden, true);
    assert.equal(picker.rows[2].hidden, false);
    assert.deepEqual(picker.options.children.map((row) => row.dataset.mobileCategory), ['', '13', '12']);
    picker.trigger.click();
    assert.equal(picker.documentRoot.activeElement, picker.rows[0]);
    assert.equal(controller.filters.category, '12');
    picker.sync(controller.filters, { all_count: 10, categories: [{ id: 12, count: 5 }, { id: 13, count: 5 }] });
    assert.equal(picker.rows[1].hidden, false);
    assert.deepEqual(picker.options.children.map((row) => row.dataset.mobileCategory), ['', '12', '13']);
    picker.sync(controller.filters, { all_count: 0, categories: [] });
    assert.equal(picker.rows[0].hidden, false);
    assert.equal(picker.rows[1].hidden, true);
    assert.equal(picker.rows[2].hidden, true);
});
