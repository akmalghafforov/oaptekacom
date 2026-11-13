import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeBulkSubscriptions } from '../../resources/js/bulk-subscriptions.js';

function control(properties = {}) {
    return { ...properties, listeners: {}, addEventListener(type, listener) { this.listeners[type] = listener; } };
}

function harness(values = ['10', '10', '20'], selected = []) {
    const selectPage = control({ checked: false });
    const count = {};
    const opener = {};
    const apply = {};
    const filters = control();
    const window = control();
    const checkboxes = values.map((value) => control({ value, checked: selected.includes(value) }));
    const inputs = [...new Set(values)].map((value) => ({ name: 'user_ids[]', value, disabled: !selected.includes(value) }));
    const elements = { '[data-select-page]': selectPage, '[data-selected-count]': count, '[data-bulk-open]': opener, '[data-bulk-apply]': apply };
    initializeBulkSubscriptions({
        querySelector: (selector) => elements[selector],
        querySelectorAll: (selector) => selector === '[data-account-checkbox]' ? checkboxes : inputs,
        ownerDocument: { querySelector: () => filters, defaultView: window },
    });
    const change = (index, checked) => {
        checkboxes[index].checked = checked;
        checkboxes[index].listeners.change();
    };
    const submittedIds = () => inputs.filter((input) => !input.disabled).map((input) => input.value);
    return { selectPage, count, opener, apply, checkboxes, filters, window, change, submittedIds };
}

test('empty selection disables bulk actions and submits no accounts', () => {
    const { count, opener, apply, selectPage, submittedIds } = harness();
    assert.equal(count.textContent, '0');
    assert.equal(opener.disabled, true);
    assert.equal(apply.disabled, true);
    assert.equal(selectPage.checked, false);
    assert.deepEqual(submittedIds(), []);
});

test('history rows synchronize selection and count and submit each account once', () => {
    const { change, count, checkboxes, selectPage, opener, apply, submittedIds } = harness();
    change(1, true);
    assert.deepEqual(checkboxes.map((checkbox) => checkbox.checked), [true, true, false]);
    assert.equal(count.textContent, '1');
    assert.equal(selectPage.indeterminate, true);
    assert.equal(opener.disabled, false);
    assert.equal(apply.disabled, false);
    assert.deepEqual(submittedIds(), ['10']);
    change(0, false);
    assert.deepEqual(checkboxes.map((checkbox) => checkbox.checked), [false, false, false]);
    assert.equal(opener.disabled, true);
    assert.deepEqual(submittedIds(), []);
});

test('select all toggles every account on the current page', () => {
    const { selectPage, count, checkboxes, submittedIds } = harness();
    selectPage.checked = true;
    selectPage.listeners.change();
    assert.equal(count.textContent, '2');
    assert.equal(selectPage.indeterminate, false);
    assert.deepEqual(checkboxes.map((checkbox) => checkbox.checked), [true, true, true]);
    assert.deepEqual(submittedIds(), ['10', '20']);
    selectPage.checked = false;
    selectPage.listeners.change();
    assert.equal(count.textContent, '0');
    assert.deepEqual(submittedIds(), []);
});

test('validation recovery restores a unique selection and synchronizes history rows', () => {
    const { count, selectPage, opener, submittedIds } = harness(['10', '10', '20'], ['10']);
    assert.equal(count.textContent, '1');
    assert.equal(selectPage.indeterminate, true);
    assert.equal(opener.disabled, false);
    assert.deepEqual(submittedIds(), ['10']);
});

test('filter changes and history navigation clear selection', () => {
    const first = harness(['10', '10', '20'], ['10']);
    first.filters.listeners.change();
    assert.equal(first.count.textContent, '0');
    assert.deepEqual(first.submittedIds(), []);
    const second = harness(['10'], ['10']);
    second.window.listeners.pageshow({ persisted: true });
    assert.equal(second.opener.disabled, true);
    assert.deepEqual(second.submittedIds(), []);
    const newPage = harness(['30']);
    assert.equal(newPage.count.textContent, '0');
    assert.deepEqual(newPage.submittedIds(), []);
});

test('no selectable rows disables select all', () => {
    const { selectPage, opener } = harness([]);
    assert.equal(selectPage.disabled, true);
    assert.equal(opener.disabled, true);
});
