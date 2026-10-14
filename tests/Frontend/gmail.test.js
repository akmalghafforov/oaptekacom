import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeGmailActions } from '../../resources/js/gmail.js';

function fixture() {
    const submit = [];
    const buttons = [{ disabled: false }, { disabled: true }];
    const status = { hidden: true };
    let restore;
    initializeGmailActions({
        querySelectorAll: (selector) => selector.endsWith('button') ? buttons : [
            { addEventListener: (_, handler) => submit.push(handler) },
            { addEventListener: (_, handler) => submit.push(handler) },
        ],
        querySelector: () => status,
    }, { addEventListener: (_, handler) => { restore = handler; } });
    return { submit, buttons, status, restore };
}

test('Gmail submission shows loading and blocks duplicate actions across forms', () => {
    const { submit, buttons, status } = fixture();
    submit[0]({ defaultPrevented: false });
    assert.equal(status.hidden, false);
    assert.ok(buttons.every((button) => button.disabled));
    let prevented = false;
    submit[1]({ defaultPrevented: false, preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
});

test('history navigation restores enabled controls while preserving setup-disabled connect', () => {
    const { submit, buttons, status, restore } = fixture();
    submit[0]({ defaultPrevented: false });
    restore();
    assert.equal(status.hidden, true);
    assert.equal(buttons[0].disabled, false);
    assert.equal(buttons[1].disabled, true);
    submit[0]({ defaultPrevented: false });
    assert.equal(status.hidden, false);
});

test('cancelled submission does not disable Gmail actions', () => {
    const { submit, buttons, status } = fixture();
    submit[0]({ defaultPrevented: true });
    assert.equal(status.hidden, true);
    assert.equal(buttons[0].disabled, false);
});
