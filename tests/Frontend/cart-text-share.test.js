import test from 'node:test';
import assert from 'node:assert/strict';
import { createCartTextShare } from '../../resources/js/cart-text-share.js';

for (const api of ['native', 'clipboard']) {
    test(`${api} sharing waits for a second click and calls the browser API synchronously`, async () => {
        let resolve;
        const pending = new Promise((done) => { resolve = done; });
        const sent = [];
        const states = [];
        let preparing = 0;
        const navigator = api === 'native'
            ? { share: (data) => { sent.push(data.text); return Promise.resolve(); } }
            : { clipboard: { writeText: (text) => { sent.push(text); return Promise.resolve(); } } };
        const sharing = createCartTextShare({ prepareText: () => { preparing++; return pending; }, navigator, onState: (state) => states.push(state) });

        const firstClick = sharing.activate();
        await sharing.activate();
        assert.equal(preparing, 1);
        assert.deepEqual(sent, []);
        resolve({ share_text: 'Current basket', excluded: 1 });
        await firstClick;
        assert.deepEqual(sent, []);
        assert.equal(states.at(-1).ready, true);
        const secondClick = sharing.activate();
        assert.deepEqual(sent, ['Current basket\nИсключено недоступных позиций: 1. Они сохранены в корзине.']);
        await secondClick;
        assert.equal(states.at(-1).ready, false);
    });
}

test('quantity edits invalidate prepared text and any preparation in flight', async () => {
    let resolve;
    const pending = new Promise((done) => { resolve = done; });
    const sent = [];
    const states = [];
    let next = pending;
    const sharing = createCartTextShare({ prepareText: () => next, navigator: { share: (data) => sent.push(data.text) }, onState: (state) => states.push(state) });
    const first = sharing.activate();
    sharing.invalidate();
    resolve({ share_text: 'Old basket', excluded: 0 });
    await first;
    assert.equal(states.at(-1).ready, false);
    next = Promise.resolve({ share_text: 'New basket', excluded: 0 });
    await sharing.activate();
    sharing.invalidate();
    await sharing.activate();
    assert.deepEqual(sent, []);
    await sharing.activate();
    assert.deepEqual(sent, ['New basket']);
});

test('failed validation and stale mode cannot prepare or share text', async () => {
    let blocked = false;
    const states = [];
    let calls = 0;
    const sharing = createCartTextShare({
        prepareText: async () => { calls++; throw new Error('Save failed'); },
        navigator: { share: () => assert.fail('Must not share') },
        onState: (state) => states.push(state), isBlocked: () => blocked,
    });
    await sharing.activate();
    assert.ok(states.some((state) => state.message));
    assert.equal(states.at(-1).ready, false);
    blocked = true;
    await sharing.activate();
    assert.equal(calls, 1);
});
