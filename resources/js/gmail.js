export function initializeGmailActions(document, window) {
    const forms = document.querySelectorAll('[data-gmail-action]');
    if (!forms.length) return;
    const buttons = document.querySelectorAll('[data-gmail-action] button');
    const disabled = new Map([...buttons].map((button) => [button, button.disabled]));
    const status = document.querySelector('[data-gmail-loading]');
    let busy = false;

    for (const form of forms) {
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) return;
            if (busy) {
                event.preventDefault();
                return;
            }
            busy = true;
            for (const button of buttons) button.disabled = true;
            if (status) status.hidden = false;
        });
    }
    window.addEventListener('pageshow', () => {
        busy = false;
        for (const button of buttons) button.disabled = disabled.get(button);
        if (status) status.hidden = true;
    });
}
