import test from 'node:test';
import assert from 'node:assert/strict';
import { createCartSaveCoordinator, initializeCartSaveBeforeSubmit } from '../../resources/js/cart-save-coordinator.js';

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

function submitHarness(flush) {
    const feedback = { hidden: true, textContent: 'Не удалось сохранить количество.' };
    let listener;
    let submitted = 0;
    let localFeedback;
    const form = {
        addEventListener: (type, callback) => { listener = callback; },
        querySelector: () => localFeedback,
        append: (element) => { localFeedback = element; },
        requestSubmit: () => { submitted++; listener({ preventDefault: () => assert.fail('Resubmission must proceed') }); },
    };
    initializeCartSaveBeforeSubmit(form, { flush, isBlocked: () => false, feedback, createElement: () => ({ dataset: {}, setAttribute() {} }) });
    return { submit: () => listener({ preventDefault() {}, submitter: {} }), count: () => submitted, feedback };
}

test('price acceptance waits for the debounced quantity save before submitting', async () => {
    const group = { desired: 3, confirmed: 7, error: false };
    const coordinator = createCartSaveCoordinator(new Map([['item', group]]));
    let release;
    const pending = new Promise((resolve) => { release = resolve; });
    group.flush = () => coordinator.enqueue(async () => { await pending; group.confirmed = group.desired; });
    const form = submitHarness(() => coordinator.flush());

    const submission = form.submit();
    await Promise.resolve();
    assert.equal(form.count(), 0);
    release();
    await submission;
    assert.equal(group.confirmed, 3);
    assert.equal(form.count(), 1);
});

test('failed quantity saves keep price acceptance on the page with feedback', async () => {
    const form = submitHarness(async () => { throw new Error('Save failed'); });
    await form.submit();
    assert.equal(form.count(), 0);
    assert.equal(form.feedback.hidden, false);
});
