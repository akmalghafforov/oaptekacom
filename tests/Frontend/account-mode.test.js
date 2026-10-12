import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeAccountMode } from '../../resources/js/account-mode.js';

function harness({ authenticated = true, storageFails = false } = {}) {
    const documentListeners = {};
    const windowListeners = {};
    const notice = { hidden: true };
    const button = { disabled: false };
    const logoutButton = { disabled: false };
    const businessForm = { hasAttribute: () => false, querySelector: () => ({}), querySelectorAll: () => [button] };
    const logoutForm = { hasAttribute: () => true, querySelector: () => ({}), querySelectorAll: () => [logoutButton] };
    const events = [];
    const values = new Map();
    const document = {
        querySelector: (selector) => selector === '[data-mode-stale]' ? notice : authenticated ? { content: selector.includes('mode-version') ? 'version-one' : '7' } : null,
        querySelectorAll: (selector) => selector === 'form' ? [businessForm, logoutForm] : [],
        addEventListener: (type, listener) => { documentListeners[type] = listener; },
        dispatchEvent: (event) => { events.push(event.type); documentListeners[event.type]?.(event); },
    };
    const window = {
        addEventListener: (type, listener) => { windowListeners[type] = listener; },
        CustomEvent: class { constructor(type) { this.type = type; } },
        localStorage: {
            setItem: (key, value) => { if (storageFails) throw new Error('Storage unavailable'); values.set(key, value); },
            getItem: (key) => values.get(key),
        },
    };
    initializeAccountMode(document, window);
    return { document, documentListeners, windowListeners, notice, button, logoutButton, businessForm, logoutForm, events, values };
}

test('a different generation marks the tab stale and prevents business submissions but permits logout', () => {
    const state = harness();
    state.windowListeners.storage({ key: 'oapteka-mode:7', newValue: 'version-two' });
    assert.equal(state.notice.hidden, false);
    assert.equal(state.button.disabled, true);
    assert.equal(state.logoutButton.disabled, false);
    let prevented = 0;
    state.documentListeners.submit({ target: state.businessForm, preventDefault: () => prevented++, stopImmediatePropagation: () => {} });
    state.documentListeners.submit({ target: state.logoutForm, preventDefault: () => prevented++, stopImmediatePropagation: () => {} });
    assert.equal(prevented, 1);
});

test('other accounts and unchanged generations do not disable forms', () => {
    const state = harness();
    state.windowListeners.storage({ key: 'oapteka-mode:8', newValue: 'version-two' });
    state.windowListeners.storage({ key: 'oapteka-mode:7', newValue: 'version-one' });
    assert.equal(state.notice.hidden, true);
    assert.equal(state.button.disabled, false);
});

test('a page restored from browser cache detects an obsolete generation', () => {
    const state = harness();
    state.values.set('oapteka-mode:7', 'version-three');
    state.windowListeners.pageshow({ persisted: true });
    assert.equal(state.notice.hidden, false);
    assert.equal(state.button.disabled, true);
});

test('a server conflict marks the page stale when browser storage is unavailable', () => {
    const state = harness({ storageFails: true });
    state.document.dispatchEvent({ type: 'account-mode-stale' });
    assert.equal(state.notice.hidden, false);
    assert.equal(state.button.disabled, true);
});

test('guest pages do not initialize mode notifications', () => {
    const state = harness({ authenticated: false });
    assert.deepEqual(state.windowListeners, {});
    assert.equal(state.values.size, 0);
});
