import test from 'node:test';
import assert from 'node:assert/strict';
import { CatalogSearchController, initializeCatalogCart, initializeCatalogQuantities } from '../../resources/js/catalog-search.js';

const deferred = () => {
    let resolve;
    const promise = new Promise((finish) => { resolve = finish; });
    return { promise, resolve };
};
const response = (offerId, quantity = 10, total = quantity) => ({
    ok: true, status: 200,
    json: async () => ({ item: { offer_id: Number(offerId), quantity, remove_url: `/cart/items/${offerId}` }, cart: { total_quantity: total } }),
});
const element = () => ({
    attributes: {}, hidden: false, disabled: false,
    classList: { values: new Set(), toggle(name, enabled) { enabled ? this.values.add(name) : this.values.delete(name); }, contains(name) { return this.values.has(name); } },
    setAttribute(name, value) { this.attributes[name] = value; }, removeAttribute(name) { delete this.attributes[name]; }, focus() {}, select() {},
});

const offerInstance = (id, { canAdd = true, quantity = 0 } = {}) => {
    const offer = { ...element(), dataset: { offerId: String(id), cartSavedQuantity: String(quantity) } };
    const input = { ...element(), value: '1' };
    const addButton = element(); const removeButton = element(); const editButton = element();
    const editor = { ...element(), hidden: true, value: String(quantity), matches: (selector) => selector === '[data-quantity-editor]' };
    const quantityError = element(); const error = { ...element(), hidden: true };
    const badge = { textContent: String(quantity) }; const status = element();
    const add = { ...element(), dataset: { cartCanAdd: String(canAdd) }, querySelectorAll: (selector) => selector === 'input' ? [input] : selector === 'button' ? [addButton] : [input, addButton] };
    const form = (kind) => ({
        action: kind === 'add' ? `/offers/${id}/cart` : `/cart/items/${id}`,
        elements: { quantity: input }, querySelector: () => ({ value: 'csrf' }), querySelectorAll: () => [removeButton],
        closest: (selector) => selector === '[data-offer-id]' ? offer : selector === `[data-cart-${kind === 'add' ? 'form' : 'remove-form'}]` ? forms[kind] : null,
    });
    const forms = { add: form('add'), remove: form('remove') };
    const inCart = { ...element(), querySelector: () => forms.remove, querySelectorAll: (selector) => selector === '[data-cart-quantity]' ? [badge] : [] };
    const quantityControl = { querySelector: (selector) => ({ '[data-quantity-edit]': editButton, '[data-quantity-editor]': editor, '[data-quantity-error]': quantityError })[selector], closest: (selector) => selector === '[data-offer-id]' ? offer : inCart };
    editor.closest = (selector) => selector === '[data-offer-id]' ? offer : quantityControl;
    editButton.querySelector = () => badge;
    editButton.closest = (selector) => selector === '[data-quantity-edit]' ? editButton : quantityControl;
    offer.querySelector = (selector) => ({ '[data-cart-add-controls]': add, '[data-cart-in-cart-controls]': inCart, '[data-cart-form]': forms.add, '[data-cart-error]': error })[selector];
    offer.querySelectorAll = (selector) => ({ '[data-cart-status]': [status], '[data-catalog-quantity]': [quantityControl], '[data-quantity-editor]': [editor], '[data-cart-in-cart-controls] button': [editButton, removeButton] })[selector] ?? [];
    add.classList.toggle('hidden', quantity > 0); inCart.classList.toggle('hidden', quantity === 0);
    return Object.assign(offer, { input, addButton, removeButton, editButton, editor, error, add, inCart, badge, forms, quantityControl });
};

const harness = (fetcher = async (url, options) => response(url.match(/\d+/)[0], options.method === 'DELETE' ? 0 : JSON.parse(options.body).quantity)) => {
    const handlers = {}; const calls = []; const announcements = {}; const badge = element(); const events = [];
    let offers = [];
    const root = {
        querySelector: () => announcements,
        querySelectorAll: (selector) => {
            if (selector === '[data-offer-id]') return offers;
            const matches = offers.filter((offer) => offer.dataset.offerId === selector.match(/"(\d+)"/)?.[1]);
            return selector.endsWith(' [data-catalog-quantity]') ? matches.map((offer) => offer.quantityControl) : matches;
        },
        addEventListener(name, handler) { (handlers[name] ??= []).push(handler); },
    };
    const documentRoot = { querySelector: () => ({ content: 'mode-version' }), querySelectorAll: () => [badge], dispatchEvent: (event) => events.push(event.type) };
    const cart = initializeCatalogCart(root, { fetcher: (...args) => { calls.push(args); return fetcher(...args); }, documentRoot });
    const render = (ids = [], options) => { offers = ids.flatMap((id) => Array.from({ length: 3 }, () => offerInstance(id, options))); cart.sync(); return offers; };
    const submit = (offer, kind = 'add') => Promise.all(handlers.submit.map((handler) => handler({ target: offer.forms[kind], preventDefault() {} })));
    return { cart, render, submit, calls, announcements, badge, events, get offers() { return offers; },
        quantities(fetcher) { initializeCatalogQuantities(root, { busy: cart.busy, documentRoot, fetcher, onBusyChange: cart.sync, onUpdate: cart.update }); },
        fire(name, target, key) { handlers[name].forEach((handler) => handler({ target, key, preventDefault() {} })); },
    };
};

test('search Ко-амл, add 10, remove, clear, search Тимоло, then add 10 from Азия Фарм', async () => {
    const h = harness(); const searches = [];
    const search = new CatalogSearchController({
        fetcher: async (filters) => { h.cart.startSearch(); searches.push(filters.q ?? ''); return { ids: filters.q === 'Ко-амл' ? [42] : filters.q === 'Тимоло' ? [73, 74] : [42, 73, 74], pagination: {} }; },
        onData: (data) => h.render(data.ids), onState() {}, onFilters() {},
    });
    await search.submit('Ко-амл');
    h.offers[0].input.value = '10'; await h.submit(h.offers[0]);
    h.offers.forEach((offer) => { assert.equal(offer.badge.textContent, 10); assert.equal(offer.inCart.classList.contains('hidden'), false); });
    assert.equal(h.badge.textContent, 10);
    await h.submit(h.offers[1], 'remove');
    h.offers.forEach((offer) => { assert.equal(offer.addButton.disabled, false); assert.equal(offer.inCart.classList.contains('hidden'), true); });
    assert.equal(h.badge.textContent, 0);
    await search.submit(''); await search.submit('Тимоло');
    h.offers[0].input.value = '10'; await h.submit(h.offers[0]);
    assert.deepEqual(searches, ['Ко-амл', '', 'Тимоло']);
    assert.deepEqual(h.calls.map(([url, options]) => [url, options.method]), [['/offers/42/cart', 'POST'], ['/cart/items/42', 'DELETE'], ['/offers/73/cart', 'POST']]);
    assert.deepEqual(JSON.parse(h.calls[2][1].body), { quantity: 10 });
    assert.equal(h.calls[2][1].headers['X-Mode-Version'], 'mode-version');
    h.offers.slice(0, 3).forEach((offer) => assert.equal(offer.badge.textContent, 10));
    h.offers.slice(3).forEach((offer) => assert.equal(offer.addButton.disabled, false));
});

test('duplicate layouts share pending state while independent offers can submit', async () => {
    const first = deferred(); const second = deferred();
    const h = harness((url) => url.includes('/42/') ? first.promise : second.promise);
    h.render([42, 73]); h.offers[0].input.value = '10';
    const adding = h.submit(h.offers[0]); await h.submit(h.offers[1]);
    h.offers.slice(0, 3).forEach((offer) => { assert.equal(offer.addButton.disabled, true); assert.equal(offer.input.disabled, true); });
    h.offers.slice(3).forEach((offer) => assert.equal(offer.addButton.disabled, false));
    const independent = h.submit(h.offers[3]);
    assert.equal(h.calls.length, 2);
    second.resolve(response(73, 1)); await independent;
    assert.equal(h.cart.busy.has('42'), true);
    first.resolve(response(42)); await adding;
    assert.equal(h.cart.busy.size, 0);
    h.offers.forEach((offer) => assert.equal(offer.removeButton.disabled, false));
    const removing = h.submit(h.offers[0], 'remove'); await h.submit(h.offers[2], 'remove');
    await removing;
    assert.equal(h.calls.length, 3);
});

test('replacement results apply pending state only to the same offer and retain the completed state', async () => {
    const pending = deferred(); const h = harness(() => pending.promise);
    h.render([42]); h.offers[0].input.value = '10'; const adding = h.submit(h.offers[0]);
    h.render([73]); assert.equal(h.offers[0].addButton.disabled, false);
    h.render([42, 73]);
    assert.equal(h.offers[0].addButton.disabled, true); assert.equal(h.offers[0].input.value, '10');
    assert.equal(h.offers[3].addButton.disabled, false);
    pending.resolve(response(42)); await adding;
    assert.equal(h.offers[0].removeButton.disabled, false);
    h.render([42]);
    assert.equal(h.offers[0].badge.textContent, 10);
    assert.equal(h.offers[0].forms.remove.action, '/cart/items/42');
    assert.equal(h.offers[0].inCart.classList.contains('hidden'), false);
});

test('fresh searches use server membership while late search responses retain newer cart changes', async () => {
    const h = harness(); h.render([42]);
    h.cart.startSearch(); await h.submit(h.offers[0]); h.render([42]);
    assert.equal(h.offers[0].badge.textContent, 1);
    assert.equal(h.offers[0].addButton.disabled, true);
    h.cart.startSearch(); h.render([42], { quantity: 7 });
    assert.equal(h.offers[0].badge.textContent, '7');
    h.cart.startSearch(); h.render([42]);
    assert.equal(h.offers[0].addButton.disabled, false);
});

test('availability changes during a failed request survive pending-state cleanup', async () => {
    const pending = deferred(); const h = harness(() => pending.promise);
    h.render([42]); const adding = h.submit(h.offers[0]);
    h.cart.startSearch(); h.render([42], { canAdd: false });
    pending.resolve({ ok: false, status: 404, json: async () => ({}) }); await adding;
    h.offers.forEach((offer) => { assert.equal(offer.addButton.disabled, true); assert.equal(offer.input.disabled, true); assert.equal(offer.error.hidden, false); });
    assert.equal(h.cart.busy.size, 0);
    h.render([73]); assert.equal(h.offers[0].addButton.disabled, false);
});

const failures = [
    ['quantity validation', async () => ({ ok: false, status: 422, json: async () => ({ message: 'Generic', errors: { quantity: ['Запрошенное количество превышает остаток поставщика.'] } }) }), /Запрошенное количество/],
    ['cart validation', async () => ({ ok: false, status: 422, json: async () => ({ errors: { cart: ['Подтвердите новую цену.'] } }) }), /Подтвердите новую цену/],
    ['unavailable offer', async () => ({ ok: false, status: 404, json: async () => ({ message: 'Not found' }) }), /Предложение недоступно/],
    ['network', async () => { throw new TypeError('Failed to fetch'); }, /Ошибка сети/],
    ['invalid JSON', async () => ({ ok: true, json: async () => { throw new SyntaxError('Invalid JSON'); } }), /Некорректный ответ сервера/],
    ['incorrect identity', async () => response(99), /Некорректный ответ сервера/],
    ['missing cart', async () => ({ ok: true, json: async () => ({ item: { offer_id: 42, quantity: 10 } }) }), /Некорректный ответ сервера/],
    ['missing quantity', async () => ({ ok: true, json: async () => ({ item: { offer_id: 42, remove_url: '/cart/items/42' }, cart: { total_quantity: 10 } }) }), /Некорректный ответ сервера/],
    ['server', async () => ({ ok: false, status: 500, json: async () => ({}) }), /Не удалось добавить/],
    ['empty server message', async () => ({ ok: false, status: 500, json: async () => ({ message: '' }) }), /Не удалось добавить/],
    ['account mode', async () => ({ ok: false, status: 409, json: async () => ({ message: 'Режим аккаунта изменился.' }) }), /Режим аккаунта/],
];
for (const [name, failure, expected] of failures) {
    test(`${name} feedback is visible beside all matching products, preserves quantity and permits retry`, async () => {
        let fail = true; const h = harness((...args) => fail ? failure(...args) : response(42));
        h.render([42, 73]); h.offers[0].input.value = '10'; await h.submit(h.offers[0]);
        h.offers.slice(0, 3).forEach((offer) => { assert.equal(offer.error.hidden, false); assert.match(offer.error.textContent, expected); assert.equal(offer.input.value, '10'); assert.equal(offer.addButton.disabled, false); });
        assert.equal(h.offers[3].error.hidden, true); assert.match(h.announcements.textContent, expected);
        if (name === 'account mode') assert.deepEqual(h.events, ['account-mode-stale']);
        assert.equal(h.cart.busy.size, 0);
        h.offers[0].input.value = '8'; h.cart.sync(); assert.equal(h.offers[0].input.value, '8');
        h.render([42]); assert.equal(h.offers[0].input.value, '10'); assert.equal(h.offers[0].error.hidden, false);
        fail = false; await h.submit(h.offers[0]);
        h.offers.forEach((offer) => { assert.equal(offer.error.hidden, true); assert.equal(offer.removeButton.disabled, false); });
    });
}

test('failed removal keeps cart controls and quantity available for retry after search replacement', async () => {
    const pending = deferred(); let fail = true;
    const h = harness(() => fail ? pending.promise : response(42, 0));
    h.render([42], { quantity: 10 }); const removing = h.submit(h.offers[0], 'remove');
    h.render([42], { quantity: 10 }); assert.equal(h.offers[0].removeButton.disabled, true);
    pending.resolve({ ok: false, status: 500, json: async () => ({}) }); await removing;
    assert.match(h.offers[0].error.textContent, /Не удалось удалить/);
    assert.equal(h.offers[0].badge.textContent, '10'); assert.equal(h.offers[0].removeButton.disabled, false);
    fail = false; await h.submit(h.offers[0], 'remove');
    assert.equal(h.offers[0].addButton.disabled, false); assert.equal(h.offers[0].error.hidden, true);
});

test('availability restrictions survive cart updates and blocked submissions', async () => {
        const h = harness(); h.render([42], { canAdd: false });
        await h.submit(h.offers[0]); assert.equal(h.calls.length, 0);
        h.cart.update({ offer_id: 42, quantity: 10 });
        h.cart.update({ offer_id: 42, quantity: 0 });
        h.offers.forEach((offer) => { assert.equal(offer.addButton.disabled, true); assert.equal(offer.input.disabled, true); });
        await h.submit(h.offers[0]); assert.equal(h.calls.length, 0);
});

test('quantity saves share the offer lock with removal and keep replacement controls synchronized', async () => {
    const pending = deferred(); const h = harness();
    h.render([42], { quantity: 3 }); h.quantities(() => pending.promise);
    const original = h.offers[0]; h.fire('click', original.editButton); original.editor.value = '10';
    h.fire('keydown', original.editor, 'Enter');
    await h.submit(original, 'remove'); assert.equal(h.calls.length, 0);
    h.render([42], { quantity: 3 }); assert.equal(h.offers[0].removeButton.disabled, true);
    pending.resolve(response(42)); await new Promise((resolve) => setImmediate(resolve));
    h.offers.forEach((offer) => { assert.equal(offer.badge.textContent, 10); assert.equal(offer.removeButton.disabled, false); assert.equal(offer.editor.readOnly, false); });
    h.cart.sync(); assert.equal(h.offers[0].badge.textContent, 10);
});
