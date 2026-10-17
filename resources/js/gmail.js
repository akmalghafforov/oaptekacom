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

export function initializeGmailProgress(document, window) {
    const panel = document.querySelector('[data-gmail-progress]');
    if (!panel || panel.dataset.active !== 'true') return;
    const status = panel.querySelector('[data-gmail-poll-status]');
    let polls = 0;
    let stopped = false;
    let timer;
    let deadlineTimer;
    const controller = new AbortController();
    const stop = (message) => {
        stopped = true;
        window.clearTimeout(timer);
        window.clearTimeout(deadlineTimer);
        controller.abort();
        if (message) status.textContent = message;
    };
    const timeoutMessage = 'Автообновление остановлено через две минуты. Нажмите «Обновить статус».';
    const poll = async () => {
        if (stopped) return;
        polls++;
        try {
            const response = await window.fetch(panel.dataset.statusUrl, {headers: {Accept: 'application/json'}, cache: 'no-store', signal: controller.signal});
            if (!response.ok) throw new Error('status');
            const result = await response.json();
            if (stopped) return;
            const intakeReason = panel.querySelector('[data-gmail-intake-reason]');
            if (intakeReason) intakeReason.textContent = result.reason ?? '';
            for (const attachment of result.attachments) {
                const rows = [...document.querySelectorAll('[data-attachment-part]')].filter(row => row.dataset.attachmentPart === attachment.part_id);
                for (const row of rows) {
                    const outcome = attachment.outcome;
                    const badge = row.querySelector('[data-attachment-status]');
                    if (badge) {
                        const content = badge.firstElementChild ?? badge;
                        content.textContent = outcome.label;
                        const colors = ['completed', 'superseded'].includes(outcome.status)
                            ? 'bg-accent-surface text-accent ring-success-border'
                            : outcome.status === 'failed' ? 'bg-danger-surface text-danger ring-danger-border'
                                : 'bg-warning-surface text-warning ring-warning-border';
                        content.className = `inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${colors}`;
                    }
                    const reason = row.querySelector('[data-attachment-reason]');
                    if (reason) reason.textContent = outcome.reason ?? '';
                    const duplicate = row.querySelector('[data-attachment-duplicate]');
                    if (duplicate) duplicate.hidden = !outcome.duplicate;
                    const link = row.querySelector('[data-attachment-import]');
                    if (link) {
                        link.hidden = !outcome.url;
                        if (outcome.url) {
                            link.href = outcome.url;
                            link.textContent = `${outcome.status === 'failed' ? 'Открыть прайс-лист и повторить' : 'Открыть импорт'} #${outcome.import_id}`;
                        }
                    }
                }
            }
            if (!result.active) {
                stop('Обработка завершена. Статусы вложений обновлены.');
                return;
            }
            if (polls >= 40) {
                stop(timeoutMessage);
                return;
            }
            timer = window.setTimeout(poll, 3000);
        } catch {
            if (!stopped) stop('Не удалось обновить статус. Нажмите «Обновить статус».');
        }
    };
    timer = window.setTimeout(poll, 3000);
    deadlineTimer = window.setTimeout(() => stop(timeoutMessage), 120000);
    window.addEventListener('pagehide', () => stop());
}
