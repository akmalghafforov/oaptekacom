import test from 'node:test';
import assert from 'node:assert/strict';
import { createSupplierFileShare } from '../../resources/js/supplier-file-share.js';

function fixture(overrides = {}) {
    const states = [];
    const calls = [];
    const sharing = createSupplierFileShare({
        flush: async () => calls.push('flush'),
        fetchFile: async () => { calls.push('fetch'); return { ok: true, headers: new Headers({ 'Content-Type': 'application/pdf' }), blob: async () => new Blob(['pdf']) }; },
        navigator: { canShare: () => true, share: async (data) => calls.push(data) },
        makeFile: (blob, name, type) => ({ blob, name, type }),
        onState: (state) => states.push(state),
        ...overrides,
    });
    return { sharing, states, calls };
}

test('prepares after saving quantities and shares only on a separate action', async () => {
    const { sharing, states, calls } = fixture();
    await sharing.prepare('/pdf', 'pdf');
    assert.deepEqual(calls, ['flush', 'fetch']);
    assert.equal(states.find((s) => s.ready)?.supported, true);
    await sharing.share();
    assert.equal(calls[2].files[0].name, 'oapteka.pdf');
    sharing.invalidate();
    await sharing.share();
    assert.equal(calls.length, 3);
});

test('unsupported devices retain downloadable file', async () => {
    const { sharing, states } = fixture({ navigator: {} });
    await sharing.prepare('/pdf', 'pdf');
    const ready = states.find((s) => s.ready);
    assert.equal(ready.supported, false);
    assert.ok(ready.file);
    assert.match(ready.message, /вручную/);
});

test('cancellation produces no error, other share failures provide download instructions', async () => {
    let name = 'AbortError';
    const { sharing, states } = fixture({ navigator: { canShare: () => true, share: async () => { throw { name }; } } });
    await sharing.prepare('/pdf', 'pdf');
    const count = states.length;
    await sharing.share();
    assert.equal(states.length, count);
    name = 'NotAllowedError';
    await sharing.share();
    assert.match(states.at(-1).message, /Скачайте/);
});

test('save and generation errors are retryable', async () => {
    let fail = true;
    const { sharing, states } = fixture({ flush: async () => { if (fail) throw new Error(); } });
    await sharing.prepare('/pdf', 'pdf');
    assert.ok(states.some((s) => s.message?.includes('Не удалось')));
    assert.equal(states.at(-1).busy, false);
    fail = false;
    await sharing.prepare('/pdf', 'pdf');
    assert.ok(states.some((s) => s.ready));
    const failure = fixture({ fetchFile: async () => ({ ok: false }) });
    await failure.sharing.prepare('/pdf', 'pdf');
    assert.ok(failure.states.some((s) => s.message?.includes('Не удалось')));
});

test('quantity edits during generation discard stale files', async () => {
    let resolve;
    const pending = new Promise((r) => { resolve = r; });
    const { sharing, states } = fixture({ flush: () => pending });
    const preparation = sharing.prepare('/pdf', 'pdf');
    sharing.invalidate();
    resolve();
    await preparation;
    assert.equal(states.some((s) => s.ready), false);
    assert.equal(states.at(-1).busy, false);
    assert.match(states.at(-2).message, /Подготовьте PDF или Excel ещё раз/);
});

for (const [format, label, mime] of [['pdf', 'PDF', 'application/pdf'], ['excel', 'Excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']]) {
    test(`${label} stays busy through saving, fetching and reading the file and rejects duplicate requests`, async () => {
        const save = Promise.withResolvers();
        const fetch = Promise.withResolvers();
        const blob = Promise.withResolvers();
        let requests = 0;
        const { sharing, states } = fixture({ flush: () => save.promise, fetchFile: () => { requests++; return fetch.promise; } });
        const preparation = sharing.prepare('/export', format);
        assert.equal(states.at(-1).message, `Готовим ${label}…`);
        assert.equal(states.at(-1).busy, true);
        await sharing.prepare('/duplicate', format);
        assert.equal(requests, 0);
        save.resolve();
        await Promise.resolve();
        assert.equal(requests, 1);
        assert.equal(states.some((s) => s.busy === false), false);
        fetch.resolve({ ok: true, headers: new Headers({ 'Content-Type': mime }), blob: () => blob.promise });
        await Promise.resolve();
        assert.equal(states.some((s) => s.busy === false), false);
        blob.resolve(new Blob(['file']));
        await preparation;
        assert.equal(states.find((s) => s.ready).format, format);
        assert.equal(states.at(-1).busy, false);
    });
}

test('throwing sharing detection preserves download and format switching replaces the file', async () => {
    const { sharing, states, calls } = fixture({
        navigator: { share: async (data) => calls.push(data), canShare: () => { throw new Error(); } },
        fetchFile: async (url) => ({ ok: true, headers: new Headers({ 'Content-Type': url === '/pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), blob: async () => new Blob(['file']) }),
    });
    await sharing.prepare('/pdf', 'pdf');
    assert.equal(states.find((s) => s.ready).supported, false);
    await sharing.prepare('/excel', 'excel');
    await sharing.share();
    assert.equal(calls.at(-1).files[0].name, 'oapteka.xlsx');
    sharing.invalidate();
    assert.equal(states.at(-1).ready, false);
});
