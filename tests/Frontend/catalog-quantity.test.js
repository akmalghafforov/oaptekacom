import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeCatalogQuantities } from '../../resources/js/catalog-search.js';

const harness = (fetcher = async () => ({ ok: true, json: async () => ({ item: { quantity: 7 }, cart: { total_quantity: 12 } }) })) => {
    const handlers = {};
    const quantity = { textContent: '3' };
    const error = { hidden: true };
    const input = { hidden: true, value: '3', focus() { this.focused = true; }, select() { this.selected = true; }, setAttribute(name, value) { this[name] = value; }, removeAttribute(name) { delete this[name]; }, matches: () => true };
    const button = { hidden: false, querySelector: () => quantity, focus() { this.focused = true; } };
    const form = { action: '/cart/items/9', querySelector: () => ({ value: 'csrf' }), querySelectorAll: () => [] };
    const controls = { classList: { toggle() {} }, querySelector: () => form, querySelectorAll: (selector) => selector === '[data-cart-quantity]' ? [quantity] : [] };
    const offer = { dataset: { offerId: '42' }, querySelectorAll: (selector) => selector === '[data-catalog-quantity]' ? [control] : [], querySelector: (selector) => selector === '[data-cart-in-cart-controls]' ? controls : null };
    const control = { closest: (selector) => selector === '[data-offer-id]' ? offer : controls, querySelector: (selector) => ({ '[data-quantity-edit]': button, '[data-quantity-editor]': input, '[data-quantity-error]': error })[selector] };
    input.closest = (selector) => selector === '[data-offer-id]' ? offer : control;
    const target = { closest: (selector) => selector === '[data-quantity-edit]' ? button : control };
    const root = { addEventListener: (name, handler) => { handlers[name] = handler; }, querySelectorAll: (selector) => selector.endsWith('[data-catalog-quantity]') ? [control] : [offer, offer] };
    const badge = { classList: { toggle() {} } };
    const documentRoot = { querySelector: () => null, querySelectorAll: () => [badge] };
    const calls = [];
    const busy = initializeCatalogQuantities(root, { documentRoot, fetcher: (...args) => { calls.push(args); return fetcher(...args); } });
    const open = () => handlers.click({ target });
    const key = (key) => handlers.keydown({ target: input, key, preventDefault() {} });
    const blur = () => handlers.focusout({ target: input });
    const settle = () => new Promise((resolve) => setImmediate(resolve));
    return { input, button, error, quantity, badge, calls, busy, open, key, blur, settle };
};

test('badge opens current quantity focused and selected; Escape and unchanged blur close without requests', () => {
    const h = harness();
    h.open();
    assert.equal(h.input.value, '3');
    assert.equal(h.input.hidden, false);
    assert.equal(h.input.focused, true);
    assert.equal(h.input.selected, true);
    h.input.value = '20';
    h.key('Escape');
    h.blur();
    assert.equal(h.button.hidden, false);
    h.open();
    h.blur();
    assert.equal(h.input.hidden, true);
    assert.equal(h.calls.length, 0);
});

for (const trigger of ['blur', 'Enter']) {
    test(`${trigger} replaces quantity and synchronizes badges`, async () => {
        const h = harness();
        h.open();
        h.input.value = '7';
        trigger === 'blur' ? h.blur() : h.key('Enter');
        await h.settle();
        assert.equal(h.calls[0][0], '/cart/items/9');
        assert.equal(h.calls[0][1].method, 'PATCH');
        assert.deepEqual(JSON.parse(h.calls[0][1].body), { quantity: 7 });
        assert.equal(h.quantity.textContent, 7);
        assert.equal(h.badge.textContent, 12);
        assert.equal(h.input.hidden, true);
    });
}

test('Enter followed by blur and repeated Enter issue one request and lock the offer', async () => {
    let finish;
    const h = harness(() => new Promise((resolve) => { finish = resolve; }));
    h.open();
    h.input.value = '7';
    h.key('Enter');
    h.blur();
    h.key('Enter');
    assert.equal(h.calls.length, 1);
    assert.equal(h.busy.has('42'), true);
    finish({ ok: true, json: async () => ({ item: { quantity: 7 }, cart: { total_quantity: 12 } }) });
    await h.settle();
    assert.equal(h.busy.size, 0);
    h.blur();
    assert.equal(h.calls.length, 1);
});

for (const value of ['', '0', '-1', '1.5', '1000000', '1e2']) {
    test(`invalid quantity ${JSON.stringify(value)} keeps editor available`, () => {
        const h = harness();
        h.open();
        h.input.value = value;
        h.blur();
        assert.equal(h.calls.length, 0);
        assert.equal(h.error.hidden, false);
        assert.equal(h.input.hidden, false);
        assert.equal(h.quantity.textContent, '3');
    });
}

for (const failure of ['validation', 'network']) {
    test(`${failure} failure preserves saved badge and allows retry`, async () => {
        const h = harness(async () => {
            if (failure === 'network') throw new TypeError('Failed to fetch');
            return { ok: false, json: async () => ({ errors: { quantity: ['Недостаточно товара.'] } }) };
        });
        h.open();
        h.input.value = '7';
        h.blur();
        await h.settle();
        assert.equal(h.quantity.textContent, '3');
        assert.equal(h.error.hidden, false);
        assert.match(h.error.textContent, failure === 'network' ? /Ошибка сети/ : /Недостаточно товара/);
        assert.equal(h.input.hidden, false);
        assert.equal(h.input.readOnly, false);
        h.key('Enter');
        await h.settle();
        assert.equal(h.calls.length, 2);
    });
}

test('busy offer cannot start an edit or save', () => {
    const h = harness();
    h.busy.add('42');
    h.open();
    assert.equal(h.input.hidden, true);
    h.input.hidden = false;
    h.input.value = '7';
    h.key('Enter');
    assert.equal(h.calls.length, 0);
});
