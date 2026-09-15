import test from 'node:test';
import assert from 'node:assert/strict';
import { CatalogSearchController } from '../../resources/js/catalog-search.js';

const deferred = () => { let resolve; const promise = new Promise((done) => { resolve = done; }); return { promise, resolve }; };
const harness = (fetcher = async () => ({ fragments: { cards: '<article />' }, pagination: { next_cursor: null, has_more: false } })) => {
    const states = []; const data = []; const filters = [];
    const controller = new CatalogSearchController({ fetcher, onState: (value) => states.push(value), onData: (value, append) => data.push([value, append]), onFilters: (value, push) => filters.push([{ ...value }, push]) });
    return { controller, states, data, filters };
};

test('typing changes only the draft and submit performs the request', async () => {
    let requests = 0;
    const { controller, states } = harness(async () => { requests++; return { fragments: { cards: '' }, pagination: { next_cursor: null, has_more: false } }; });
    controller.setDraftQuery('аспирин');
    assert.equal(requests, 0);
    await controller.submit('аспирин');
    assert.equal(requests, 1);
    assert.equal(states.at(-1), 'empty');
});

test('empty query browses while short non-empty query is rejected', async () => {
    let requested;
    const { controller, states } = harness(async (filters) => { requested = filters; return { fragments: { cards: '' }, pagination: { next_cursor: null, has_more: false } }; });
    await controller.submit('аб');
    assert.equal(states.at(-1), 'invalid');
    await controller.submit('');
    assert.equal(requested.view, 'list');
    assert.equal('q' in requested, false);
});

test('committed filters use arrays and reset pagination', async () => {
    const requests = [];
    const { controller, filters } = harness(async (input) => { requests.push(input); return { fragments: { cards: '' }, pagination: { next_cursor: null, has_more: false } }; });
    controller.cursor = 'old';
    await controller.setFilters({ cities: ['Душанбе'], suppliers: ['4'], sort: 'updated_desc' });
    assert.deepEqual(requests[0].cities, ['Душанбе']);
    assert.equal(controller.cursor, null);
    assert.equal(filters.at(-1)[1], true);
});

test('late responses are ignored after a newer committed search', async () => {
    const first = deferred(); const second = deferred(); let index = 0;
    const { controller, data } = harness(() => [first, second][index++].promise);
    const oldRequest = controller.submit('первый');
    const newRequest = controller.submit('второй');
    second.resolve({ fragments: { cards: 'second' }, pagination: { next_cursor: null, has_more: false } }); await newRequest;
    first.resolve({ fragments: { cards: 'first' }, pagination: { next_cursor: null, has_more: false } }); await oldRequest;
    assert.deepEqual(data.map(([response]) => response.fragments?.cards).filter(Boolean), ['second']);
});

test('load more uses one explicit cursor request', async () => {
    const requests = [];
    const { controller, data } = harness(async (input) => { requests.push(input); return requests.length === 1 ? { fragments: { cards: 'first' }, pagination: { next_cursor: 'next', has_more: true } } : { fragments: { cards: 'more' }, pagination: { next_cursor: null, has_more: false } }; });
    await controller.submit('аспирин');
    await controller.loadMore();
    assert.equal(requests[1].cursor, 'next');
    assert.equal(data.at(-1)[1], true);
});

test('view changes are requested but excluded from shareable filters', async () => {
    let requested;
    const { controller, filters } = harness(async (input) => { requested = input; return { fragments: { cards: '' }, pagination: { next_cursor: null, has_more: false } }; });
    await controller.setView('grid');
    assert.equal(requested.view, 'grid');
    assert.equal('view' in filters.at(-1)[0], false);
});
