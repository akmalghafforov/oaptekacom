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

test('network errors retain displayed progress and schedule another check', async () => {
    const state = setup([], '0', false);
    await state.poll();
    assert.equal(state.renders.length, 0);
    assert.match(state.feedback.textContent, /Не удалось/);
    assert.equal(state.hasTimer(), true);
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
        assert.match(state.feedback.textContent, /Не удалось.*Последние данные/);
    }
});

test('empty service history remains a table row spanning all columns', async () => {
    const state = tableSetup([]);
    await state.poll();
    assert.equal(state.container.children[0].tagName, 'tr');
    assert.equal(state.container.children[0].children[0].colSpan, 6);
});


test('polling backs off transient failures then resumes successful monitoring', async () => {
    let callback;
    let delay;
    let failing = true;
    const feedback = {};
    const rendered = [];
    const panel = {dataset: {statusUrl: '/supplier', watchNew: '1'}, ownerDocument: {hidden: false}, querySelector: () => feedback};
    initializeImportProgress(panel, {
        fetch: async () => ({ok: !failing, json: async () => ({entries: [{active: true}]})}),
        setTimeout: (fn, ms) => {callback = fn; delay = ms;}, clearTimeout() {}, addEventListener() {},
    }, (_, entries) => rendered.push(entries));
    await callback();
    assert.equal(delay, 6000);
    await callback();
    assert.equal(delay, 12000);
    assert.equal(rendered.length, 0);
    failing = false;
    await callback();
    assert.equal(delay, 3000);
    assert.equal(rendered.length, 1);
    assert.match(feedback.textContent, /Статус обновлён/);
});

test('hidden tabs make no status requests and repeated failures eventually stop', async () => {
    let callback;
    let requests = 0;
    let cleared = false;
    const panel = {dataset: {statusUrl: '/supplier', watchNew: '1'}, ownerDocument: {hidden: true}, querySelector: () => ({})};
    initializeImportProgress(panel, {
        fetch: async () => {requests += 1; throw new Error('offline');},
        setTimeout: fn => {callback = fn;}, clearTimeout: () => {cleared = true;}, addEventListener() {},
    }, () => {});
    await callback();
    assert.equal(requests, 0);
    panel.ownerDocument.hidden = false;
    for (let count = 0; count < 6; count += 1) await callback();
    assert.equal(requests, 6);
    assert.equal(cleared, true);
});

function monitoredPanel(fetch) {
    let sequence = 0;
    const timers = new Map();
    const listeners = {};
    const documentListeners = {};
    const feedback = {};
    const renders = [];
    const panel = {
        dataset: {statusUrl: '/queue', watchNew: '1'},
        ownerDocument: {hidden: false, addEventListener: (name, callback) => { documentListeners[name] = callback; }},
        querySelector: () => feedback,
    };
    const browser = {
        fetch,
        setTimeout: (callback, delay) => { const id = ++sequence; timers.set(id, {callback, delay}); return id; },
        clearTimeout: id => timers.delete(id),
        addEventListener: (name, callback) => { listeners[name] = callback; },
    };
    const stop = initializeImportProgress(panel, browser, (_, entries) => renders.push(entries));
    const tick = delay => {
        const next = [...timers.entries()].find(([, timer]) => delay === undefined || timer.delay === delay);
        assert.ok(next, `Missing timer at ${delay}`);
        timers.delete(next[0]);
        return next[1].callback();
    };
    return {panel, timers, listeners, documentListeners, renders, feedback, tick, stop};
}

const progressResponse = revision => ({ok: true, json: async () => ({revision, entries: [{active: true, label: String(revision)}]})});

test('visibility changes do not overlap an existing status request', async () => {
    let resolve;
    let requests = 0;
    const state = monitoredPanel(() => {requests++; return new Promise(done => {resolve = done;});});
    const pending = state.tick(3000);
    state.documentListeners.visibilitychange();
    state.documentListeners.visibilitychange();
    assert.equal(requests, 1);
    resolve(progressResponse(1));
    await pending;
    assert.equal(state.renders.length, 1);
    assert.equal(state.timers.size, 1);
    state.stop();
});

test('polling ignores a response with an older server revision', async () => {
    const responses = [progressResponse(8), progressResponse(7)];
    const state = monitoredPanel(async () => responses.shift());
    await state.tick(3000);
    await state.tick(3000);
    assert.equal(state.renders.length, 1);
    assert.equal(state.renders[0][0].label, '8');
    state.stop();
});

test('a timed out request preserves displayed state and schedules recovery', async () => {
    const state = monitoredPanel((_, {signal}) => new Promise((resolve, reject) => {
        signal.addEventListener('abort', () => reject(new Error('aborted')));
    }));
    const pending = state.tick(3000);
    state.tick(15000);
    await pending;
    assert.equal(state.renders.length, 0);
    assert.match(state.feedback.textContent, /Данные устарели/);
    assert.equal([...state.timers.values()][0].delay, 6000);
    state.stop();
});

test('upstream polling rate limits respect Retry-After across visibility changes', async () => {
    let requests = 0;
    const state = monitoredPanel(async () => {
        requests++;
        return {ok: false, status: 429, headers: {get: () => '120'}};
    });
    await state.tick(3000);
    assert.equal([...state.timers.values()][0].delay, 120000);
    state.documentListeners.visibilitychange();
    assert.equal(requests, 1);
    assert.equal(state.renders.length, 0);
    state.stop();
});

test('page restoration restarts monitoring after an obsolete in-flight response settles', async () => {
    let resolve;
    const state = monitoredPanel(() => new Promise(done => {resolve = done;}));
    const pending = state.tick(3000);
    state.listeners.pagehide();
    state.listeners.pageshow({persisted: true});
    resolve(progressResponse(2));
    await pending;
    assert.equal(state.renders.length, 0);
    assert.equal([...state.timers.values()][0].delay, 0);
    state.stop();
});
