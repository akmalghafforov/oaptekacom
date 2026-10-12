export function initializeAccountMode(document, window) {
    const version = document.querySelector('meta[name="mode-version"]')?.content;
    const user = document.querySelector('meta[name="mode-user"]')?.content;
    if (!version || !user) return;
    const key = `oapteka-mode:${user}`;
    let stale = false;
    const markStale = () => {
        if (stale) return;
        stale = true;
        const notice = document.querySelector('[data-mode-stale]');
        if (notice) notice.hidden = false;
        document.querySelectorAll('form').forEach((form) => {
            if (!form.hasAttribute('data-mode-independent') && form.querySelector('[name="_mode_version"]')) {
                form.querySelectorAll('button, input, select, textarea').forEach((control) => { control.disabled = true; });
            }
        });
        document.querySelectorAll('[data-supplier-share], [data-cart-share], [data-file-prepare], [data-file-share]').forEach((control) => { control.disabled = true; });
        document.dispatchEvent(new window.CustomEvent('account-mode-stale'));
    };
    document.addEventListener('account-mode-stale', markStale);
    document.addEventListener('submit', (event) => {
        if (stale && !event.target.hasAttribute('data-mode-independent') && event.target.querySelector('[name="_mode_version"]')) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
    window.addEventListener('storage', (event) => {
        if (event.key === key && event.newValue && event.newValue !== version) markStale();
    });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        try {
            if (window.localStorage.getItem(key) !== version) markStale();
        } catch { /* Server validation remains authoritative. */ }
    });
    try { window.localStorage.setItem(key, version); } catch { /* Server validation remains authoritative. */ }
}
