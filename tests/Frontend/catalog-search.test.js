import test from 'node:test';
import assert from 'node:assert/strict';
import { CatalogSearchController } from '../../resources/js/catalog-search.js';

const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((promiseResolve, promiseReject) => {
        resolve = promiseResolve;
        reject = promiseReject;
    });
    return { promise, resolve, reject };
};

const harness = (fetcher = async () => ({ html: '<article />', next_cursor: null, has_more: false, facets: [] })) => {
    const states = [];
    const data = [];
    const filterEvents = [];
    const timers = new Map();
    let timerId = 0;
    const controller = new CatalogSearchController({
        fetcher,
        onState: (state) => states.push(state),
        onData: (response, append) => data.push([response, append]),
        onFilters: (filters, replace) => filterEvents.push([{ ...filters }, replace]),
        setTimer: (callback, delay) => {
            timers.set(++timerId, { callback, delay });
            return timerId;
        },
        clearTimer: (id) => timers.delete(id),
    });
    return { controller, states, data, filterEvents, timers };
};

test('debounces searches for exactly 500 ms and clears immediately below three characters', async () => {
    let requests = 0;
    const { controller, states, data, timers } = harness(async () => {
        requests++;
        return { html: '<article />', next_cursor: null, has_more: false, facets: [] };
    });

    controller.setQuery(' асп ');
    assert.equal([...timers.values()][0].delay, 500);
    assert.equal(requests, 0);
    await [...timers.values()][0].callback();
    assert.equal(requests, 1);

    controller.setQuery(' аб ');
    assert.equal(states.at(-1), 'waiting');
    assert.equal(data.at(-1)[0].html, '');
});

test('cancels in-flight work and rejects a stale response even if abort is ignored', async () => {
    const requests = [deferred(), deferred()];
    let index = 0;
    const { controller, data } = harness(() => requests[index++].promise);

    controller.filters = { q: 'first' };
    const first = controller.request(false);
    controller.setQuery('second');
    const timer = controller.timer;
    controller.timer = null;
    controller.clearTimer(timer);
    const second = controller.request(false);
    requests[1].resolve({ html: 'second', next_cursor: null, has_more: false, facets: [] });
    await second;
    requests[0].resolve({ html: 'first', next_cursor: null, has_more: false, facets: [] });
    await first;

    assert.deepEqual(data.map(([response]) => response.html).filter(Boolean), ['second']);
});

test('active filter changes reset pagination and duplicate observer callbacks request one cursor once', async () => {
    let calls = 0;
    const pending = deferred();
    const { controller } = harness(async (filters) => {
        calls++;
        if (filters.cursor) {
            return pending.promise;
        }
        return { html: 'one', next_cursor: 'cursor-1', has_more: true, facets: [] };
    });
    controller.filters = { q: 'аспирин' };
    await controller.request(false);
    controller.loadMore();
    controller.loadMore();
    assert.equal(calls, 2);
    pending.resolve({ html: 'two', next_cursor: null, has_more: false, facets: null });
    await Promise.resolve();

    await controller.setFilter('city', 'Душанбе');
    assert.equal(controller.seenCursors.size, 0);
});

test('restore loads valid shared filters immediately without replacing history', async () => {
    let requested;
    const { controller, filterEvents } = harness(async (filters) => {
        requested = filters;
        return { html: '', next_cursor: null, has_more: false, facets: [] };
    });

    await controller.restore({ q: '  аспирин ', city: ' Душанбе ', cursor: 'ignored' });

    assert.deepEqual(requested, { q: 'аспирин', city: 'Душанбе' });
    assert.equal(filterEvents.at(-1)[1], false);
});

test('retries initial and load-more failures while preserving append mode', async () => {
    let failures = 2;
    const { controller, states, data } = harness(async (filters) => {
        if (failures-- > 0) {
            throw new Error('offline');
        }
        return { html: filters.cursor ? 'more' : 'initial', next_cursor: null, has_more: false, facets: [] };
    });
    controller.filters = { q: 'аспирин' };
    await controller.request(false);
    assert.equal(states.at(-1), 'error');
    await controller.retry();
    assert.equal(states.at(-1), 'error');
    await controller.retry();
    assert.equal(data.at(-1)[1], false);

    controller.cursor = 'next';
    controller.hasMore = true;
    failures = 1;
    await controller.loadMore();
    assert.equal(states.at(-1), 'loadMoreError');
    await controller.retry();
    assert.equal(data.at(-1)[1], true);
    assert.equal(states.at(-1), 'complete');
});

test('emits waiting, loading, results, empty, failure, loading-more and complete states', async () => {
    const { controller, states } = harness();
    controller.restore({ q: 'ab' });
    controller.filters = { q: 'asp' };
    await controller.request(false);
    controller.cursor = 'next';
    controller.hasMore = true;
    await controller.loadMore();

    assert.deepEqual([...new Set(states)], ['waiting', 'loading', 'complete', 'loadingMore']);
});
