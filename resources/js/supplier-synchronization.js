export function initializeMappingForm(form) {
    const mode = form.querySelector('[data-mapping-mode]');
    const panels = form.querySelectorAll('[data-mapping-panel]');
    const update = () => {
        panels.forEach((panel) => {
            const active = panel.dataset.mappingPanel === mode.value;
            panel.hidden = !active;
            panel.querySelectorAll('input, select').forEach((control) => {
                control.disabled = !active;
            });
        });
    };

    mode.addEventListener('change', update);
    update();
}

export function initializeSyncSubmit(form, browser) {
    const buttons = [...form.querySelectorAll('button[type="submit"], button:not([type])')];
    const initial = buttons.map((button) => ({ button, text: button.textContent, disabled: button.disabled }));
    let submitting = false;
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        if (submitting) {
            event.preventDefault();
            return;
        }
        submitting = true;
        form.setAttribute('aria-busy', 'true');
        buttons.forEach((button) => {
            button.disabled = true;
            button.textContent = 'Отправка…';
        });
    });
    browser.addEventListener('pageshow', () => {
        submitting = false;
        form.removeAttribute('aria-busy');
        initial.forEach(({ button, text, disabled }) => {
            button.disabled = disabled;
            button.textContent = text;
        });
    });
}
