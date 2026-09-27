import test from 'node:test';
import assert from 'node:assert/strict';
import { createSupplierFileRenderer } from '../../resources/js/supplier-file-renderer.js';

function element() {
    return { hidden: true, disabled: false, textContent: '', setAttribute(key, value) { this[key] = value; }, removeAttribute(key) { delete this[key]; } };
}

for (const [format, label] of [['pdf', 'PDF'], ['excel', 'Excel']]) {
    test(`${label} popup shows progress, actions and cleans up replaced URLs`, () => {
        const buttons = [element(), element()];
        const nodes = Object.fromEntries(['share', 'download', 'status', 'spinner'].map((key) => [`[data-file-${key}]`, element()]));
        const panel = { ...element(), querySelectorAll: () => buttons, querySelector: (key) => nodes[key] };
        const revoked = [];
        let count = 0;
        const renderer = createSupplierFileRenderer(panel, { createObjectURL: () => `blob:${++count}`, revokeObjectURL: (url) => revoked.push(url) });
        const download = nodes['[data-file-download]'];
        const share = nodes['[data-file-share]'];
        renderer.render({ busy: true, ready: false, message: `Готовим ${label}…` });
        assert.equal(panel['aria-busy'], 'true');
        assert.ok(buttons.every((button) => button.disabled));
        assert.equal(nodes['[data-file-spinner]'].hidden, false);
        assert.equal(nodes['[data-file-status]'].textContent, `Готовим ${label}…`);
        assert.equal(download.hidden, true);
        renderer.render({ ready: true, format, supported: true, file: { name: 'export' } });
        renderer.render({ busy: false });
        assert.equal(download.textContent, `Скачать ${label}`);
        assert.equal(share.textContent, `Поделиться ${label} через WhatsApp`);
        assert.equal(download.hidden, false);
        assert.equal(share.hidden, false);
        assert.equal(panel['aria-busy'], 'false');
        assert.ok(buttons.every((button) => !button.disabled));
        assert.equal(nodes['[data-file-spinner]'].hidden, true);
        renderer.render({ ready: false, busy: true });
        assert.deepEqual(revoked, ['blob:1']);
        assert.equal(download.href, undefined);
        assert.equal(share.hidden, true);
        renderer.render({ ready: true, format, supported: false, file: { name: 'export' } });
        assert.equal(share.hidden, true);
        assert.equal(download.hidden, false);
        renderer.cleanup();
        renderer.cleanup();
        assert.deepEqual(revoked, ['blob:1', 'blob:2']);
    });
}
