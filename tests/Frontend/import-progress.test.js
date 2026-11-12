import test from 'node:test';
import assert from 'node:assert/strict';
import { initializeImportProgress } from '../../resources/js/import-progress.js';

function setup(entries, watchNew = '0', ok = true) {
    let next;
    const feedback = {};
    const renders = [];
    const panel = { dataset: { statusUrl: '/price-list-imports/17', watchNew }, ownerDocument: {hidden: false}, querySelector: () => feedback };
    const browser = {fetch: async () => ({ok, json: async () => ({entries})}), setTimeout: callback => { next = callback; return 1; }, clearTimeout: () => {next = null;}, addEventListener() {}};
    initializeImportProgress(panel, browser, (_, result) => renders.push(result));
    return {poll: async () => { const callback = next; next = null; await callback(); }, hasTimer: () => !!next, feedback, renders, panel};
}

test('updates active work without reloading the page', async () => {
    const state = setup([{active: true, label: 'Обработка'}]);
    await state.poll();
    assert.equal(state.renders[0][0].label, 'Обработка');
    assert.equal(state.hasTimer(), true);
});

test('detail stops polling at completion while list watches for new downloads', async () => {
    const detail = setup([{active: false}]);
    await detail.poll();
    assert.equal(detail.hasTimer(), false);
    const list = setup([], '1');
    await list.poll();
    assert.equal(list.hasTimer(), true);
});

test('network errors retain displayed progress and offer manual refresh', async () => {
    const state = setup([], '0', false);
    await state.poll();
    assert.equal(state.renders.length, 0);
    assert.match(state.feedback.textContent, /Не удалось/);
    assert.equal(state.hasTimer(), false);
});
