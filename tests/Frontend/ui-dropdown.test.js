import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeDropdown } from '../../resources/js/ui-dropdown.js';

function fixture() {
    const listeners = new Map();
    const document = { activeElement: null, addEventListener: (name, callback) => listeners.set(`document:${name}`, callback) };
    const node = () => ({ disabled: false, focus() { document.activeElement = this; }, setAttribute(name, value) { this[name] = value; }, addEventListener(name, callback) { listeners.set(`${this.name}:${name}`, callback); } });
    const trigger = { ...node(), name: 'trigger' };
    const choices = [node(), node()];
    const menu = { ...node(), name: 'menu', hidden: true, querySelectorAll: () => choices };
    const root = { ...node(), name: 'root', ownerDocument: document, querySelector: (selector) => selector.includes('trigger') ? trigger : menu, contains: (target) => [trigger, menu, ...choices].includes(target) };
    initializeDropdown(root);
    const key = (key) => listeners.get('root:keydown')({ key, preventDefault() {} });
    return { listeners, document, trigger, menu, choices, key };
}

test('dropdown opens from the keyboard, navigates choices, and restores focus on Escape', () => {
    const { key, trigger, menu, document, choices } = fixture();
    key('ArrowDown');
    assert.equal(menu.hidden, false);
    assert.equal(trigger['aria-expanded'], 'true');
    assert.equal(document.activeElement, choices[0]);
    key('ArrowDown');
    assert.equal(document.activeElement, choices[1]);
    key('ArrowDown');
    assert.equal(document.activeElement, choices[0]);
    key('End');
    assert.equal(document.activeElement, choices[1]);
    key('Home');
    assert.equal(document.activeElement, choices[0]);
    key('Escape');
    assert.equal(menu.hidden, true);
    assert.equal(document.activeElement, trigger);
});

test('selection restores trigger focus while outside clicks and Tab dismiss without stealing focus', () => {
    const { listeners, trigger, menu, document, key } = fixture();
    listeners.get('trigger:click')();
    listeners.get('menu:click')({ target: { closest: () => true } });
    assert.equal(menu.hidden, true);
    assert.equal(document.activeElement, trigger);
    key('ArrowUp');
    const outside = {};
    document.activeElement = outside;
    listeners.get('document:click')({ target: outside });
    assert.equal(menu.hidden, true);
    assert.equal(document.activeElement, outside);
    key('ArrowDown');
    key('Tab');
    assert.equal(menu.hidden, true);
});
