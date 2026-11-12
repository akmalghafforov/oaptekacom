import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeMappingForm, initializeSyncSubmit } from '../../resources/js/supplier-synchronization.js';

test('mapping mode shows only its fields and preserves values when switching back', () => {
    const listeners = {};
    const mode = { value: '', addEventListener: (name, callback) => { listeners[name] = callback; } };
    const panels = ['existing', 'new'].map((mappingPanel) => ({
        dataset: { mappingPanel }, hidden: false,
        controls: [{ value: mappingPanel === 'new' ? 'Название' : '42', disabled: false }],
        querySelectorAll() { return this.controls; },
    }));
    initializeMappingForm({ querySelector: () => mode, querySelectorAll: () => panels });
    assert.ok(panels.every((panel) => panel.hidden && panel.controls[0].disabled));
    mode.value = 'new';
    listeners.change();
    assert.equal(panels[1].hidden, false);
    assert.equal(panels[1].controls[0].disabled, false);
    assert.equal(panels[0].controls[0].disabled, true);
    mode.value = 'existing';
    listeners.change();
    assert.equal(panels[0].hidden, false);
    assert.equal(panels[0].controls[0].disabled, false);
    mode.value = 'new';
    listeners.change();
    assert.equal(panels[1].controls[0].value, 'Название');
});

test('restored mapping selection is applied immediately', () => {
    const panel = { dataset: { mappingPanel: 'existing' }, querySelectorAll: () => [] };
    initializeMappingForm({
        querySelector: () => ({ value: 'existing', addEventListener() {} }),
        querySelectorAll: () => [panel],
    });
    assert.equal(panel.hidden, false);
});

test('submission blocks duplicates and restores button state after back navigation', () => {
    const listeners = {};
    const browserListeners = {};
    const buttons = [{ textContent: 'Сохранить', disabled: false }, { textContent: 'Недоступно', disabled: true }];
    const attributes = new Map();
    initializeSyncSubmit({
        querySelectorAll: () => buttons,
        addEventListener: (name, callback) => { listeners[name] = callback; },
        setAttribute: (name, value) => attributes.set(name, value),
        removeAttribute: (name) => attributes.delete(name),
    }, { addEventListener: (name, callback) => { browserListeners[name] = callback; } });
    listeners.submit({ defaultPrevented: true });
    assert.equal(buttons[0].disabled, false);
    listeners.submit({});
    assert.equal(buttons[0].textContent, 'Отправка…');
    assert.equal(attributes.get('aria-busy'), 'true');
    let prevented = false;
    listeners.submit({ preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    browserListeners.pageshow();
    assert.equal(buttons[0].disabled, false);
    assert.equal(buttons[0].textContent, 'Сохранить');
    assert.equal(buttons[1].disabled, true);
    assert.equal(attributes.has('aria-busy'), false);
    listeners.submit({});
    assert.equal(buttons[0].disabled, true);
});

test('saving source settings leaves the external profile form available', () => {
    const setup = () => {
        const handlers = {};
        const button = { textContent: 'Сохранить', disabled: false };
        initializeSyncSubmit({
            querySelectorAll: () => [button],
            addEventListener: (name, callback) => { handlers[name] = callback; },
            setAttribute() {}, removeAttribute() {},
        }, { addEventListener() {} });
        return { handlers, button };
    };
    const sourceSettings = setup();
    const externalProfile = setup();
    sourceSettings.handlers.submit({});
    assert.equal(sourceSettings.button.disabled, true);
    assert.equal(externalProfile.button.disabled, false);
    externalProfile.handlers.submit({});
    assert.equal(externalProfile.button.disabled, true);
});
