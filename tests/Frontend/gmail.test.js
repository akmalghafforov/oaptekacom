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

async function progressFixture(results) {
    const { initializeGmailProgress } = await import('../../resources/js/gmail.js');
    const status = { textContent: '' };
    const scheduled = [];
    const calls = [];
    const deadlines = [];
    const panel = { dataset: { active: 'true', statusUrl: '/status' }, querySelector: () => status };
    initializeGmailProgress({ querySelector: () => panel, querySelectorAll: () => [] }, {
        fetch: async (url, options) => {
            calls.push({url, options});
            const result = results.shift() ?? {active: true, attachments: []};
            if (result instanceof Error) throw result;
            return {ok: result.ok ?? true, json: async () => result};
        },
        setTimeout: (callback, delay) => { (delay === 120000 ? deadlines : scheduled).push({callback, delay}); },
        clearTimeout: () => {}, addEventListener: () => {},
    });
    return { status, scheduled, calls, deadlines };
}

test('Gmail polls every three seconds and stops when processing completes', async () => {
    const fixture = await progressFixture([{active: true, attachments: []}, {active: false, attachments: []}]);
    await fixture.scheduled.shift().callback();
    assert.equal(fixture.scheduled[0].delay, 3000);
    assert.equal(fixture.calls[0].options.cache, 'no-store');
    await fixture.scheduled.shift().callback();
    assert.equal(fixture.scheduled.length, 0);
    assert.match(fixture.status.textContent, /завершена/);
});

test('Gmail polling stops after two minutes and keeps manual refresh guidance', async () => {
    const fixture = await progressFixture([]);
    for (let poll = 0; poll < 40; poll++) await fixture.scheduled.shift().callback();
    assert.equal(fixture.scheduled.length, 0);
    assert.equal(fixture.calls.length, 40);
    assert.match(fixture.status.textContent, /две минуты/);
});

test('Gmail polling reports network or authorization failures without raw response', async () => {
    for (const failure of [new Error('secret'), {ok: false}]) {
        const fixture = await progressFixture([failure]);
        await fixture.scheduled.shift().callback();
        assert.equal(fixture.scheduled.length, 0);
        assert.match(fixture.status.textContent, /Не удалось обновить статус/);
        assert.doesNotMatch(fixture.status.textContent, /secret/);
    }
});


test('Gmail stops and aborts a stalled request at the two minute deadline', async () => {
    const fixture = await progressFixture([]);
    fixture.deadlines[0].callback();
    await fixture.scheduled.shift().callback();
    assert.equal(fixture.calls.length, 0);
    assert.match(fixture.status.textContent, /две минуты/);
});
