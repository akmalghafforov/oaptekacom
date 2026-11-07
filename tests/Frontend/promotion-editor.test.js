import test from 'node:test';
import assert from 'node:assert/strict';
import { initializePromotionEditor, promotionFormState } from '../../resources/js/promotion-editor.js';

function field(name, value, type = 'text', attributes = []) {
    return { name, value, type, disabled: false, hasAttribute: (attribute) => attributes.includes(attribute) };
}

function fixture(restoredForm = '') {
    const listeners = new Map();
    const listen = (target) => (type, callback) => listeners.set(`${target}:${type}`, callback);
    const makeForm = (name, publication = false) => ({
        name,
        elements: [field('price', '10'), field('_promotion_form', name, 'hidden')],
        querySelector: () => ({ value: name }),
        hasAttribute: (attribute) => publication && attribute === 'data-promotion-publication',
    });
    const information = makeForm('information');
    const product = makeForm('item-1');
    const review = makeForm('publication', true);
    const publication = makeForm('publish', true);
    const forms = [information, product, review];
    const notice = { hidden: true };
    const disclosure = { open: false };
    const root = {
        dataset: { restoredForm },
        querySelectorAll: (selector) => selector === '[data-promotion-form]' ? forms : [notice],
        querySelector: () => disclosure,
        addEventListener: listen('root'),
    };
    const document = { addEventListener: listen('document') };
    const confirmations = [];
    const alerts = [];
    const window = {
        location: { href: 'https://example.test/promotions/1/edit', hash: '' },
        addEventListener: listen('window'),
        queueMicrotask,
        confirm: (message) => { confirmations.push(message); return window.acceptDiscard; },
        alert: (message) => alerts.push(message),
        acceptDiscard: false,
    };
    initializePromotionEditor(root, document, window);
    const dispatch = (target, type, values = {}) => {
        const event = { target: values.target, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, ...values };
        listeners.get(`${target}:${type}`)(event);
        return event;
    };
    const edit = (form, value = '11') => { form.elements[0].value = value; dispatch('root', 'input', { target: form.elements[0] }); };
    const click = (href, extra = {}) => dispatch('document', 'click', {
        target: { closest: () => ({ href, hasAttribute: () => false, ...extra }) },
    });
    return { information, product, review, publication, notice, disclosure, window, confirmations, alerts, dispatch, edit, click };
}

test('dirty state clears after values are restored and ignores calendar controls', () => {
    const context = fixture();
    context.information.elements.push(field('starts_on-month', '4', 'select-one', ['data-date-month']));
    context.dispatch('root', 'input');
    assert.equal(context.notice.hidden, true);
    context.edit(context.product);
    assert.equal(context.notice.hidden, false);
    assert.equal(context.dispatch('window', 'beforeunload').defaultPrevented, true);
    context.edit(context.product, '10');
    assert.equal(context.notice.hidden, true);
    assert.equal(context.dispatch('window', 'beforeunload').defaultPrevented, false);
});

test('saving one form warns about other dirty forms but saving its own changes proceeds', async () => {
    const context = fixture();
    context.edit(context.product);
    assert.equal(context.dispatch('document', 'submit', { target: context.information }).defaultPrevented, true);
    assert.equal(context.confirmations.length, 1);
    assert.equal(context.dispatch('document', 'submit', { target: context.product }).defaultPrevented, false);
    await Promise.resolve();
    assert.equal(context.dispatch('window', 'beforeunload').defaultPrevented, false);
    context.dispatch('window', 'pageshow');
    assert.equal(context.dispatch('window', 'beforeunload').defaultPrevented, true);
});

test('search and deletion require confirmation before discarding editor changes', async () => {
    const context = fixture();
    context.edit(context.information);
    const search = { hasAttribute: () => false };
    assert.equal(context.dispatch('document', 'submit', { target: search }).defaultPrevented, true);
    context.window.acceptDiscard = true;
    assert.equal(context.dispatch('document', 'submit', { target: search }).defaultPrevented, false);
    await Promise.resolve();
    assert.equal(context.dispatch('window', 'beforeunload').defaultPrevented, false);
});

test('publication waits for editor saves while a moderation comment can submit itself', () => {
    const context = fixture();
    context.edit(context.information);
    context.window.acceptDiscard = true;
    assert.equal(context.dispatch('document', 'submit', { target: context.publication }).defaultPrevented, true);
    assert.match(context.alerts[0], /Сначала сохраните/);
    assert.equal(context.confirmations.length, 0);
    context.edit(context.information, '10');
    context.edit(context.review);
    assert.equal(context.dispatch('document', 'submit', { target: context.review }).defaultPrevented, false);
});

test('navigation warns but section anchors and new tabs preserve the editor', () => {
    const context = fixture();
    context.edit(context.product);
    assert.equal(context.click('https://example.test/promotions/1/edit#products').defaultPrevented, false);
    assert.equal(context.click('https://example.test/promotions', { target: '_blank' }).defaultPrevented, false);
    assert.equal(context.click('mailto:info@example.test').defaultPrevented, false);
    assert.equal(context.confirmations.length, 0);
    assert.equal(context.click('https://example.test/promotions').defaultPrevented, true);
    context.window.acceptDiscard = true;
    assert.equal(context.click('https://example.test/promotions').defaultPrevented, false);
    assert.equal(context.dispatch('window', 'beforeunload').defaultPrevented, false);
});

test('restored failed submissions remain protected before the first input event', () => {
    const context = fixture('item-1');
    assert.equal(context.notice.hidden, false);
    assert.equal(context.dispatch('window', 'beforeunload').defaultPrevented, true);
    assert.equal(context.dispatch('document', 'submit', { target: context.publication }).defaultPrevented, true);
    assert.equal(context.dispatch('document', 'submit', { target: context.product }).defaultPrevented, false);
});

test('hash navigation reveals the copy disclosure and prevented submissions keep unload protection', async () => {
    const context = fixture();
    context.window.location.hash = '#copy-products';
    context.dispatch('window', 'hashchange');
    assert.equal(context.disclosure.open, true);
    context.edit(context.product);
    const event = context.dispatch('document', 'submit', { target: context.product });
    event.preventDefault();
    await Promise.resolve();
    assert.equal(context.dispatch('window', 'beforeunload').defaultPrevented, true);
});

test('file selection, checkbox changes and empty values contribute to dirty state', () => {
    const upload = { elements: [field('materials[]', '', 'file'), field('enabled', '1', 'checkbox')] };
    upload.elements[0].files = [];
    upload.elements[1].checked = false;
    const initial = promotionFormState(upload);
    upload.elements[0].files = [{ name: 'poster.png', size: 100, lastModified: 123 }];
    assert.notEqual(promotionFormState(upload), initial);
    const singleFile = promotionFormState(upload);
    upload.elements[0].files.push({ name: 'terms.pdf', size: 200, lastModified: 456 });
    assert.notEqual(promotionFormState(upload), singleFile);
    upload.elements[0].files = [];
    upload.elements[1].checked = true;
    assert.notEqual(promotionFormState(upload), initial);
});


test('enhanced date inputs without names still protect unsaved date changes', () => {
    const visibleDate = field('', '08.11.2026', 'text', ['data-date-input']);
    visibleDate.id = 'starts_on';
    const form = { elements: [visibleDate, field('starts_on', '2026-11-08', 'hidden')] };
    const initial = promotionFormState(form);
    visibleDate.value = '09.11.2026';
    assert.notEqual(promotionFormState(form), initial);
    visibleDate.value = '08.11.2026';
    assert.equal(promotionFormState(form), initial);
});
