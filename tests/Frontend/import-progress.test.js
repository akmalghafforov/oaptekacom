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

function tableSetup(entries, ok = true) {
    const node = (tagName) => ({tagName, children: [], textContent: '', className: '', append(...children) { this.children.push(...children); }, replaceChildren(...children) { this.children = children; }});
    const container = node('tbody');
    const oldRow = node('tr');
    container.append(oldRow);
    const feedback = node('p');
    let next;
    const panel = {
        dataset: {progressLayout: 'table', showSource: '1', watchNew: '1', statusUrl: '/price-list-imports?tab=services&page=2'},
        ownerDocument: {hidden: false, createElement: node},
        querySelector: selector => selector === '[data-progress-entries]' ? container : feedback,
    };
    const browser = {
        fetch: async url => {
            assert.equal(url, '/price-list-imports?tab=services&page=2');
            if (ok === 'network-error') throw new Error('Network unavailable');
            return {ok, json: async () => ({entries})};
        },
        setTimeout: callback => { next = callback; return 1; }, clearTimeout: () => { next = null; }, addEventListener() {},
    };
    initializeImportProgress(panel, browser);
    return {container, oldRow, feedback, poll: async () => { await next(); }};
}

test('polling renders service history as six-cell table rows with safely escaped text', async () => {
    const unsafe = '<img src=x onerror=alert(1)>';
    const state = tableSetup([{filename: unsafe, source_label: 'iCorp', supplier: unsafe, label: 'Ошибка', status: 'failed', detail: unsafe, rows: '5 / 2 ош.', date: '12.11.2026 10:00', url: '/price-list-imports/17'}]);
    await state.poll();
    const row = state.container.children[0];
    assert.equal(row.tagName, 'tr');
    assert.equal(row.children.length, 6);
    assert.equal(row.children[0].children[0].textContent, unsafe);
    assert.equal(row.children[0].children[1].textContent, 'iCorp');
    assert.equal(row.children[1].textContent, unsafe);
    assert.equal(row.children[2].children[1].textContent, unsafe);
    assert.match(row.children[2].children[0].className, /text-danger/);
    assert.equal(row.children[3].textContent, '5 / 2 ош.');
    assert.equal(row.children[5].children[0].href, '/price-list-imports/17');
    assert.match(row.children[5].children[0].className, /text-accent/);
    const verifyNoHtml = element => {
        assert.equal(Object.hasOwn(element, 'innerHTML'), false);
        element.children.forEach(verifyNoHtml);
    };
    verifyNoHtml(row);
});

test('pre-file attempts show source, missing file and counts without an admin action', async () => {
    const state = tableSetup([{filename: null, source_label: 'PriceList.tj', supplier: 'Supplier', status: 'queued', label: 'В очереди', detail: 'Ожидание', rows: null, date: '12.11.2026 10:00', url: null}]);
    await state.poll();
    const cells = state.container.children[0].children;
    assert.equal(cells[0].children[0].textContent, 'PriceList.tj');
    assert.equal(cells[0].children[1].textContent, 'Файл ещё не получен');
    assert.equal(cells[3].textContent, '—');
    assert.equal(cells[5].children.length, 0);
});

test('polling failures retain the existing history table rows and refresh feedback', async () => {
    for (const failure of [false, 'network-error']) {
        const state = tableSetup([], failure);
        await state.poll();
        assert.equal(state.container.children[0], state.oldRow);
        assert.match(state.feedback.textContent, /Не удалось.*Обновить статус/);
    }
});

test('empty service history remains a table row spanning all columns', async () => {
    const state = tableSetup([]);
    await state.poll();
    assert.equal(state.container.children[0].tagName, 'tr');
    assert.equal(state.container.children[0].children[0].colSpan, 6);
});
