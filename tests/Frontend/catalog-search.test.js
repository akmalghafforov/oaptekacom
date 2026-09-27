import test from 'node:test';
import assert from 'node:assert/strict';
import { activeCategoryLabel, CatalogSearchController, catalogFragment, updateCartBadges, updateCatalogCategories, updateCatalogOfferCartState } from '../../resources/js/catalog-search.js';

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
