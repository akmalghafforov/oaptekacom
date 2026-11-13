export class BannerExposure {
    constructor(duration) {
        this.remaining = duration * 1000;
        this.continuous = 0;
        this.counted = false;
    }

    tick(milliseconds, visible, active, paused = false) {
        if (!visible || !active) {
            this.continuous = 0;
            return { impression: false, advance: false };
        }
        this.continuous += milliseconds;
        const impression = !this.counted && this.continuous >= 1000;
        if (impression) this.counted = true;
        if (!paused) this.remaining -= milliseconds;
        return { impression, advance: this.remaining <= 0 && !paused };
    }
}

export function initializeCatalogBanners(root, { documentRoot = document, windowRoot = window, fetcher = (...args) => fetch(...args) } = {}) {
    const carousel = root.querySelector('[data-banner-carousel]');
    if (!carousel || carousel.dataset.initialized) return null;
    carousel.dataset.initialized = 'true';
    const content = root.querySelector('[data-catalog-content]');
    const link = carousel.querySelector('[data-banner-link]');
    const image = carousel.querySelector('[data-banner-image]');
    const loading = carousel.querySelector('[data-banner-loading]');
    const controls = carousel.querySelector('[data-banner-controls]');
    const indicators = carousel.querySelector('[data-banner-indicators]');
    const pause = carousel.querySelector('[data-banner-pause]');
    let showing = false;
    let available = true;
    let busy = false;
    let visible = false;
    let loaded = false;
    let interacting = false;
    let paused = windowRoot.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    let data = null;
    let exposure = null;
    let lastTick = windowRoot.performance.now();
    let stopped = false;
    const headers = () => ({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': documentRoot.querySelector('meta[name="csrf-token"]').content, 'X-Mode-Version': documentRoot.querySelector('meta[name="mode-version"]')?.content ?? '' });
    const syncPause = () => { pause.textContent = paused ? 'Продолжить' : 'Приостановить'; pause.setAttribute('aria-pressed', String(paused)); };
    syncPause();
    const fallback = () => {
        available = false;
        showing = false;
        carousel.hidden = true;
        content.hidden = false;
        root.dispatchEvent(new windowRoot.CustomEvent('catalog-banners-unavailable'));
    };
    const next = async (options = {}) => {
        if (busy || stopped) return;
        busy = true;
        loaded = false;
        controls.querySelectorAll('button').forEach((button) => { button.disabled = true; });
        try {
            const response = await fetcher(carousel.dataset.nextUrl, { method: 'POST', headers: headers(), body: JSON.stringify(options) });
            if (!response.ok) throw new Error('Banner request failed');
            data = await response.json();
            if (!data.banner) { fallback(); return; }
            exposure = new BannerExposure(data.banner.duration);
            const banner = data.banner;
            link.href = banner.url;
            link.setAttribute('aria-label', banner.title);
            image.alt = banner.title;
            image.onload = () => { loaded = true; lastTick = windowRoot.performance.now(); };
            image.onerror = fallback;
            carousel.querySelector('[data-banner-mobile]').srcset = banner.images.mobile;
            carousel.querySelector('[data-banner-tablet]').srcset = banner.images.tablet;
            image.src = banner.images.desktop;
            loaded = image.complete && image.naturalWidth > 0;
            link.hidden = false;
            loading.hidden = true;
            controls.hidden = data.ids.length < 2;
            carousel.querySelector('[data-banner-position]').textContent = `${data.ids.indexOf(banner.id) + 1} / ${data.ids.length}`;
            indicators.replaceChildren();
            data.ids.forEach((id, index) => {
                const button = carousel.querySelector('[data-banner-indicator-template]').content.firstElementChild.cloneNode(true);
                button.type = 'button';
                button.textContent = String(index + 1);
                button.setAttribute('aria-label', `Предложение ${index + 1}`);
                if (id === banner.id) button.setAttribute('aria-current', 'true');
                button.disabled = id === banner.id || (data.remaining.length > 0 && !data.remaining.includes(id));
                button.addEventListener('click', () => next({ banner_id: id }));
                indicators.append(button);
            });
        } catch { fallback(); }
        finally {
            busy = false;
            carousel.querySelectorAll('[data-banner-next], [data-banner-previous], [data-banner-pause]').forEach((button) => { button.disabled = false; });
            lastTick = windowRoot.performance.now();
        }
    };
    carousel.querySelector('[data-banner-next]').addEventListener('click', () => next());
    carousel.querySelector('[data-banner-previous]').addEventListener('click', () => next({ previous: true }));
    carousel.addEventListener('focusin', () => { interacting = true; });
    carousel.addEventListener('focusout', (event) => { interacting = carousel.contains(event.relatedTarget); });
    carousel.addEventListener('pointerenter', () => { interacting = true; });
    carousel.addEventListener('pointerleave', () => { interacting = carousel.contains(documentRoot.activeElement); });
    pause.addEventListener('click', () => { paused = !paused; syncPause(); });
    if ('IntersectionObserver' in windowRoot) {
        new windowRoot.IntersectionObserver(([entry]) => {
            visible = entry.isIntersecting && entry.intersectionRatio >= 0.5;
            if (!visible && exposure) exposure.continuous = 0;
            lastTick = windowRoot.performance.now();
        }, { threshold: [0, 0.5, 1] }).observe(image);
    }
    documentRoot.addEventListener('visibilitychange', () => {
        if (exposure) exposure.continuous = 0;
        lastTick = windowRoot.performance.now();
    });
    documentRoot.addEventListener('account-mode-stale', () => { stopped = true; });
    windowRoot.setInterval(() => {
        const now = windowRoot.performance.now();
        const elapsed = now - lastTick;
        lastTick = now;
        if (!exposure || busy || stopped) return;
        // A long scheduling gap cannot establish continuous visibility.
        const result = exposure.tick(elapsed > 500 ? 0 : elapsed, visible && showing && loaded, !documentRoot.hidden, paused || interacting);
        if (elapsed > 500) exposure.continuous = 0;
        if (result.impression && data.impression_url) {
            fetcher(data.impression_url, { method: 'POST', headers: headers(), body: '{}' }).catch(() => {});
        }
        if (showing && visible && !documentRoot.hidden && data.banner.expires_at && Date.parse(data.banner.expires_at) <= Date.now()) next();
        else if (result.advance && data.ids.length > 1) next();
    }, 100);
    return {
        sync(query) {
            showing = available && !String(query ?? '').trim();
            carousel.hidden = !showing;
            content.hidden = showing;
            if (!showing && exposure) exposure.continuous = 0;
            lastTick = windowRoot.performance.now();
            if (showing && !data && !busy) next();
        },
        isShowing: () => showing,
    };
}
