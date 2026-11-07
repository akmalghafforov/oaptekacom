const discardMessage = 'Есть несохранённые изменения в других формах. Продолжить и потерять эти правки?';

export function promotionFormState(form) {
    return JSON.stringify([...form.elements]
        .filter((field) => (field.name || field.hasAttribute('data-date-input')) && !field.disabled && !['hidden', 'submit', 'button', 'reset'].includes(field.type)
            && !field.hasAttribute('data-date-month') && !field.hasAttribute('data-date-year'))
        .map((field) => [field.name || field.id, field.type === 'file'
            ? [...field.files].map((file) => [file.name, file.size, file.lastModified])
            : ['checkbox', 'radio'].includes(field.type) ? [field.checked, field.value] : field.value]));
}

export function initializePromotionEditor(root, document, window) {
    const forms = [...root.querySelectorAll('[data-promotion-form]')];
    const initial = new Map(forms.map((form) => [form, promotionFormState(form)]));
    const recovered = forms.find((form) => root.dataset.restoredForm && form.querySelector('[name="_promotion_form"]')?.value === root.dataset.restoredForm);
    const notices = root.querySelectorAll('[data-promotion-unsaved]');
    let leaving = false;
    const dirtyForms = () => forms.filter((form) => form === recovered || promotionFormState(form) !== initial.get(form));
    const render = () => notices.forEach((notice) => { notice.hidden = dirtyForms().length === 0; });
    root.addEventListener('input', render);
    root.addEventListener('change', render);
    root.addEventListener('reset', () => window.queueMicrotask(render));

    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        const dirty = dirtyForms().filter((form) => form !== event.target);
        if (event.target.hasAttribute('data-promotion-publication') && dirty.length) {
            event.preventDefault();
            window.alert('Сначала сохраните изменения в редакторе. Затем повторите действие публикации.');
            return;
        }
        if (dirty.length && !window.confirm(discardMessage)) {
            event.preventDefault();
            return;
        }
        window.queueMicrotask(() => { leaving = !event.defaultPrevented; });
    });
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
            || link.target === '_blank' || link.hasAttribute('download')) return;
        const target = new URL(link.href, window.location.href);
        if (!['http:', 'https:'].includes(target.protocol)) return;
        const current = new URL(window.location.href);
        if (target.origin === current.origin && target.pathname === current.pathname && target.search === current.search && target.hash) return;
        if (dirtyForms().length && !window.confirm('Есть несохранённые изменения. Уйти со страницы и потерять эти правки?')) {
            event.preventDefault();
            return;
        }
        leaving = true;
    });
    window.addEventListener('beforeunload', (event) => {
        if (!leaving && dirtyForms().length) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    window.addEventListener('pageshow', () => { leaving = false; render(); });
    const revealSection = () => {
        if (window.location.hash === '#copy-products') {
            const disclosure = root.querySelector('#copy-products');
            if (disclosure) disclosure.open = true;
        }
    };
    window.addEventListener('hashchange', revealSection);
    revealSection();
    render();
}
