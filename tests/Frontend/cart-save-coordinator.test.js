import test from 'node:test';
import assert from 'node:assert/strict';
import { createCartSaveCoordinator } from '../../resources/js/cart-save-coordinator.js';

test('serializes saves and waits for edits queued during a save', async () => {
    const calls = [];
    let release;
    const pending = new Promise((resolve) => { release = resolve; });
    const coordinator = createCartSaveCoordinator(new Map());
    coordinator.enqueue(async () => { calls.push('first'); await pending; calls.push('confirmed'); });
    coordinator.enqueue(async () => { calls.push('second'); });
    const flushed = coordinator.flush();
    await Promise.resolve();
    assert.deepEqual(calls, ['first']);
    release();
    await flushed;
    assert.deepEqual(calls, ['first', 'confirmed', 'second']);
});

test('failed saves block repeated action flushes until a new valid edit succeeds', async () => {
    const group = { error: true, confirmed: 1, desired: 1, flush() {} };
    const coordinator = createCartSaveCoordinator(new Map([['item', group]]));
    await assert.rejects(coordinator.flush());
    await assert.rejects(coordinator.flush());
    group.error = false;
    coordinator.enqueue(async () => { group.confirmed = 2; group.desired = 2; });
    await coordinator.flush();
    assert.equal(group.confirmed, 2);
});

test('stale mode prevents queued writes and actions even when it changes during flush', async () => {
    let stale = false;
    const coordinator = createCartSaveCoordinator(new Map(), () => stale);
    coordinator.enqueue(async () => { stale = true; });
    await assert.rejects(coordinator.flush());
    let called = false;
    coordinator.enqueue(async () => { called = true; });
    await assert.rejects(coordinator.flush());
    await Promise.resolve();
    assert.equal(called, false);
});
