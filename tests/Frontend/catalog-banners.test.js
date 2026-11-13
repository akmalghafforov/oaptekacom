import test from 'node:test';
import assert from 'node:assert/strict';
import { BannerExposure, initializeCatalogBanners } from '../../resources/js/catalog-banners.js';

test('an impression requires a continuous second and counts once per appearance', () => {
    const exposure = new BannerExposure(8);
    assert.equal(exposure.tick(999, true, true).impression, false);
    assert.equal(exposure.tick(1, true, true).impression, true);
    assert.equal(exposure.tick(3000, true, true).impression, false);
    assert.equal(new BannerExposure(8).tick(1000, true, true).impression, true);
});

test('hidden tabs and less than half visible interrupt exposure and pause rotation', () => {
    for (const [visible, active] of [[false, true], [true, false]]) {
        const exposure = new BannerExposure(2);
        exposure.tick(600, true, true);
        assert.deepEqual(exposure.tick(10000, visible, active), { impression: false, advance: false });
        assert.equal(exposure.tick(600, true, true).impression, false);
        assert.equal(exposure.tick(400, true, true).impression, true);
        assert.equal(exposure.tick(399, true, true).advance, false);
        assert.equal(exposure.tick(1, true, true).advance, true);
    }
});

test('explicit pause preserves duration while still recording a visible impression', () => {
    const exposure = new BannerExposure(3);
    assert.deepEqual(exposure.tick(10000, true, true, true), { impression: true, advance: false });
    assert.equal(exposure.tick(2999, true, true).advance, false);
    assert.equal(exposure.tick(1, true, true).advance, true);
});

const element = () => ({
    hidden: false, disabled: false, dataset: {}, listeners: {}, attributes: {}, children: [],
    addEventListener(name, callback) { this.listeners[name] = callback; },
    setAttribute(name, value) { this.attributes[name] = value; },
    replaceChildren() { this.children = []; },
    append(child) { this.children.push(child); },
    querySelectorAll() { return []; },
});
function harness({ count = 2, failed = false, modeVersion = null } = {}) {
    const selectors = Object.fromEntries(['link', 'image', 'loading', 'controls', 'indicators', 'pause', 'next', 'previous', 'position', 'mobile', 'tablet'].map((name) => [`[data-banner-${name}]`, element()]));
    const carousel = element();
    carousel.querySelector = (selector) => selector === '[data-banner-indicator-template]' ? { content: { firstElementChild: { cloneNode: element } } } : selectors[selector];
    carousel.dataset.nextUrl = '/next';
    const content = element();
    const root = element();
    root.querySelector = (selector) => selector === '[data-banner-carousel]' ? carousel : content;
    const events = [];
    root.dispatchEvent = (event) => events.push(event.type);
    const documentRoot = element();
    const metadata = {
        'meta[name="csrf-token"]': { content: 'catalog-banner-csrf-token' },
        'meta[name="mode-version"]': modeVersion === null ? null : { content: modeVersion },
    };
    documentRoot.querySelector = (selector) => metadata[selector] ?? null;
    documentRoot.createElement = element;
    let interval;
    let observe;
    let time = 0;
    const requests = [];
    const windowRoot = {
        performance: { now: () => time }, matchMedia: () => ({ matches: false }),
        setInterval: (callback) => { interval = callback; },
        IntersectionObserver: class { constructor(callback) { observe = callback; } observe() {} },
        CustomEvent: class { constructor(type) { this.type = type; } },
    };
    const fetcher = async (url, options) => {
        requests.push({ url, options });
        return { ok: !failed, json: async () => ({
            banner: { id: 1, title: 'Banner', duration: 2, url: '/destination', images: { desktop: '/d', tablet: '/t', mobile: '/m' } },
            ids: count === 1 ? [1] : [1, 2], remaining: count === 1 ? [] : [2], appearance: 'unique', impression_url: '/impression',
        }) };
    };
    const api = initializeCatalogBanners(root, { documentRoot, windowRoot, fetcher });
    return { api, root, carousel, content, selectors, requests, events, documentRoot,
        visible(ratio) { observe([{ isIntersecting: ratio > 0, intersectionRatio: ratio }]); },
        tick(milliseconds) { for (let i = 0; i < milliseconds; i += 100) { time += 100; interval(); } },
    };
}
const flush = async () => { await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); };

for (const modeVersion of [null, 'mode-7']) {
    test(`banner and impression requests send the CSRF token with mode version ${modeVersion}`, async () => {
        const h = harness({ modeVersion });
        h.api.sync('');
        await flush();
        h.selectors['[data-banner-image]'].onload();
        h.visible(1);
        h.tick(1000);

        assert.deepEqual(h.requests.map(({ url }) => url), ['/next', '/impression']);
        for (const { options } of h.requests) {
            assert.equal(options.method, 'POST');
            assert.equal(options.headers['X-CSRF-TOKEN'], 'catalog-banner-csrf-token');
            assert.equal(options.headers['X-Mode-Version'], modeVersion ?? '');
        }
    });
}

test('typing hides banners immediately and clearing restores the same appearance without rerender counts', async () => {
    const h = harness();
    h.api.sync('');
    await flush();
    assert.equal(h.carousel.hidden, false);
    assert.equal(h.content.hidden, true);
    assert.equal(h.requests.length, 1);
    h.api.sync('а');
    assert.equal(h.carousel.hidden, true);
    assert.equal(h.content.hidden, false);
    h.api.sync('   ');
    await flush();
    assert.equal(h.carousel.hidden, false);
    assert.equal(h.requests.length, 1);
    assert.equal(initializeCatalogBanners(h.root, { documentRoot: {}, windowRoot: {} }), null);
});

test('carousel uses image visibility, active tab, and configured duration and sends one impression', async () => {
    const h = harness();
    h.api.sync(''); await flush();
    h.selectors['[data-banner-image]'].onload();
    h.visible(0.49); h.tick(1200);
    assert.equal(h.requests.length, 1);
    h.visible(0.5); h.tick(600);
    h.documentRoot.hidden = true;
    h.documentRoot.listeners.visibilitychange(); h.tick(2000);
    h.documentRoot.hidden = false;
    h.documentRoot.listeners.visibilitychange(); h.tick(1000);
    assert.equal(h.requests.filter((request) => request.url === '/impression').length, 1);
    h.tick(400); await flush();
    assert.equal(h.requests.filter((request) => request.url === '/next').length, 2);
});

test('single banner has no controls or automatic advances', async () => {
    const h = harness({ count: 1 });
    h.api.sync(''); await flush();
    assert.equal(h.selectors['[data-banner-controls]'].hidden, true);
    h.selectors['[data-banner-image]'].onload(); h.visible(1); h.tick(10000);
    assert.equal(h.requests.filter((request) => request.url === '/next').length, 1);
    assert.equal(h.requests.filter((request) => request.url === '/impression').length, 1);
});

test('failed banner loading restores catalog browsing', async () => {
    const h = harness({ failed: true });
    h.api.sync(''); await flush();
    assert.equal(h.carousel.hidden, true);
    assert.equal(h.content.hidden, false);
    assert.deepEqual(h.events, ['catalog-banners-unavailable']);
});
