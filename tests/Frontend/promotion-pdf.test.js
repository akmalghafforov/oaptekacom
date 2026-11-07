import test from 'node:test';
import assert from 'node:assert/strict';
import { initializePromotionPdf } from '../../resources/js/promotion-pdf.js';

function node() {
    return { hidden: true, disabled: false, textContent: '', style: {}, attributes: {}, listeners: {},
        setAttribute(name, value) { this.attributes[name] = value; },
        getAttribute(name) { return this.attributes[name]; },
        addEventListener(name, action) { this.listeners[name] = action; },
    };
}

function fixture({ loadError = false, renderError = false, delay = null } = {}) {
    const nodes = Object.fromEntries(['canvas', 'page', 'error', 'container', 'prev', 'next', 'in', 'out', 'fit'].map((key) => [`[data-pdf-${key}]`, node()]));
    nodes['[data-pdf-container]'].clientWidth = 400;
    nodes['[data-pdf-canvas]'].getContext = () => ({});
    const root = { ...node(), dataset: { url: '/private/brochure.pdf' }, querySelector: (key) => nodes[key] };
    const calls = [];
    let destroyed = false;
    let documentOptions;
    const pdfjs = { getDocument(options) {
        documentOptions = options;
        return {
            promise: loadError ? Promise.reject(new Error('Invalid PDF')) : Promise.resolve({
                numPages: 3,
                async getPage(page) {
                    return {
                        getViewport: ({ scale }) => ({ width: 800 * scale, height: 1000 * scale }),
                        render(context) {
                            calls.push({ page, context });
                            return { promise: renderError ? Promise.reject(new Error('Corrupt page')) : delay?.() ?? Promise.resolve() };
                        },
                    };
                },
            }),
            destroy() { destroyed = true; },
        };
    } };
    return { nodes, root, pdfjs, calls, options: () => documentOptions, destroyed: () => destroyed };
}

test('PDF loads privately, fits width, renders at device resolution and bounds navigation', async () => {
    const f = fixture();
    const viewer = await initializePromotionPdf(f.root, f.pdfjs, { devicePixelRatio: 2 });
    assert.deepEqual(f.options(), { url: '/private/brochure.pdf', withCredentials: true, isEvalSupported: false });
    assert.equal(f.nodes['[data-pdf-page]'].textContent, '1 / 3 · 50%');
    assert.equal(f.nodes['[data-pdf-canvas]'].width, 800);
    assert.equal(f.nodes['[data-pdf-canvas]'].style.width, '400px');
    assert.equal(f.nodes['[data-pdf-prev]'].disabled, true);
    await viewer.next();
    await viewer.next();
    await viewer.next();
    assert.equal(f.calls.at(-1).page, 3);
    assert.equal(f.nodes['[data-pdf-next]'].disabled, true);
    await viewer.prev();
    assert.equal(f.calls.at(-1).page, 2);
});

test('PDF zoom preserves the page and width fitting can be restored', async () => {
    const f = fixture();
    const viewer = await initializePromotionPdf(f.root, f.pdfjs);
    await viewer.next();
    await viewer.in();
    assert.equal(f.nodes['[data-pdf-page]'].textContent, '2 / 3 · 75%');
    await viewer.out();
    assert.equal(f.nodes['[data-pdf-page]'].textContent, '2 / 3 · 50%');
    f.nodes['[data-pdf-container]'].clientWidth = 600;
    await viewer.fit();
    assert.equal(f.nodes['[data-pdf-page]'].textContent, '2 / 3 · 75%');
    for (let i = 0; i < 20; i++) await viewer.in();
    assert.equal(f.calls.at(-1).context.viewport.width, 3200);
    for (let i = 0; i < 20; i++) await viewer.out();
    assert.equal(f.calls.at(-1).context.viewport.width, 200);
});

for (const type of ['loadError', 'renderError']) {
    test(`PDF ${type} gives an accessible error while the original download remains available`, async () => {
        const f = fixture({ [type]: true });
        await initializePromotionPdf(f.root, f.pdfjs);
        assert.equal(f.nodes['[data-pdf-error]'].hidden, false);
        assert.equal(f.nodes['[data-pdf-page]'].textContent, 'Ошибка просмотра');
        assert.equal(f.root.attributes['aria-busy'], 'false');
    });
}

test('PDF prevents concurrent rendering on the same canvas and releases the loading task', async () => {
    let release;
    let delayed = false;
    const f = fixture({ delay: () => delayed ? new Promise((resolve) => { release = resolve; }) : Promise.resolve() });
    const viewer = await initializePromotionPdf(f.root, f.pdfjs);
    delayed = true;
    const next = viewer.next();
    await Promise.resolve();
    await Promise.resolve();
    const duplicate = viewer.next();
    assert.equal(f.nodes['[data-pdf-next]'].disabled, true);
    release();
    await Promise.all([next, duplicate]);
    assert.deepEqual(f.calls.map((call) => call.page), [1, 2]);
    viewer.dispose();
    await Promise.resolve();
    assert.equal(f.destroyed(), true);
});
