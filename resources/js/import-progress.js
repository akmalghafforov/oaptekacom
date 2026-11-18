export function renderImportProgress(panel, entries) {
    if (panel.dataset.progressLayout === 'table') {
        renderImportHistory(panel, entries);
        return;
    }
    const container = panel.querySelector('[data-progress-entries]');
    const doc = panel.ownerDocument;
    const cards = entries.map(entry => {
        const card = doc.createElement('div');
        for (const [value, className] of [[entry.title, 'font-semibold'], [entry.label, ''], [entry.detail, 'text-sm text-muted']]) {
            const line = doc.createElement('p');
            line.textContent = value;
            line.className = className;
            card.append(line);
        }
        if (entry.url) {
            const link = doc.createElement('a');
            link.href = entry.url;
            link.textContent = entry.action_label ?? 'Открыть подробности';
            link.className = 'inline-flex min-h-10 items-center font-semibold underline';
            card.append(link);
        }
        return card;
    });
    if (!cards.length) {
        const empty = doc.createElement('p');
        empty.textContent = 'Загрузки из iCorp и PriceList.tj появятся здесь после запуска.';
        cards.push(empty);
    }
    container.replaceChildren(...cards);
}

export function renderQueueSummary(panel, queue) {
    if (!queue || !panel.querySelectorAll) return;
    panel.querySelectorAll('[data-queue-count]').forEach(node => {
        node.textContent = queue.counts[node.dataset.queueCount] ?? 0;
    });
    const doc = panel.ownerDocument;
    const line = (text, className = 'text-sm text-muted') => {
        const node = doc.createElement('p');
        node.textContent = text;
        node.className = className;
        return node;
    };
    const groups = panel.querySelector('[data-queue-groups]');
    groups?.replaceChildren(...queue.groups.map(group => line(`Завершено ${group.finished} из ${group.total} · Успешно: ${group.succeeded} · Пропущено: ${group.skipped} · Ошибок: ${group.failed} · Требуют проверки: ${group.blocked}`)));
    const active = panel.querySelector('[data-queue-active]');
    active?.replaceChildren(...(queue.active.length ? queue.active.map(entry => {
        const card = doc.createElement('div');
        card.append(line(entry.title, 'font-semibold'), line(`${entry.lane} · ${entry.label}`, ''), line(entry.detail),
            line(`Строки: ${entry.processed} / ${entry.total || '—'} · Страницы: ${entry.downloaded_pages} / ${entry.total_pages || '—'}`),
            line(`Попытка: ${entry.retry_count + 1} · Следующий повтор: ${entry.next_retry_at ?? '—'} · Последняя активность: ${entry.heartbeat_at ?? '—'}`));
        return card;
    }) : [line('Нет выполняющихся импортов.', 'text-muted')]));
}

export function initializeImportProgress(panel, browser, render = renderImportProgress) {
    let stopped = false;
    let failures = 0;
    let lastUpdated = null;
    let timer;
    let inFlight = false;
    let controller;
    let requestId = 0;
    let revision = -1;
    let retryNotBefore = 0;
    const feedback = panel.querySelector('[data-progress-feedback]');
    const stop = () => {
        stopped = true;
        requestId += 1;
        browser.clearTimeout(timer);
        controller?.abort();
    };
    const poll = async () => {
        if (stopped || inFlight) return;
        if (panel.ownerDocument.hidden) { timer = browser.setTimeout(poll, 3000); return; }
        if (Date.now() < retryNotBefore) { timer = browser.setTimeout(poll, retryNotBefore - Date.now()); return; }
        inFlight = true;
        const currentRequest = ++requestId;
        controller = new (browser.AbortController ?? globalThis.AbortController)();
        const timeout = browser.setTimeout(() => controller.abort(), 15000);
        let delay = 3000;
        try {
            const response = await browser.fetch(panel.dataset.statusUrl, {headers: {Accept: 'application/json'}, cache: 'no-store', signal: controller.signal});
            if (response.status === 429) {
                const retry = response.headers?.get('Retry-After');
                const seconds = /^\d+$/.test(retry ?? '') ? Number(retry) : (Date.parse(retry) - Date.now()) / 1000;
                delay = Math.max(1000, Number.isFinite(seconds) ? seconds * 1000 : 60000);
                retryNotBefore = Date.now() + delay;
                throw new Error('throttled');
            }
            if (!response.ok) throw new Error('status');
            const result = await response.json();
            if (stopped || currentRequest !== requestId) return;
            if (result.revision !== undefined && result.revision < revision) return;
            revision = result.revision ?? revision;
            render(panel, result.entries);
            renderQueueSummary(panel, result.queue);
            if (panel.querySelectorAll) {
                panel.querySelectorAll('[data-supplier-field]').forEach(node => {
                    node.textContent = result[node.dataset.supplierField] ?? 'Нет данных';
                });
            }
            failures = 0;
            lastUpdated = new Date().toLocaleTimeString('ru-RU');
            feedback.textContent = `Статус обновлён: ${lastUpdated}.`;
            if (panel.dataset.watchNew !== '1' && !result.entries.some(entry => entry.unfinished ?? entry.active)) {
                feedback.textContent = 'Статус обновлён. Автообновление завершено.';
                stop();
            }
        } catch {
            if (stopped || currentRequest !== requestId) return;
            failures += 1;
            feedback.textContent = `Не удалось обновить статус. Данные устарели. Последние данные: ${lastUpdated ?? 'при открытии страницы'}. Это ошибка наблюдения; результат импорта неизвестен. ${failures <= 5 ? 'Повторяем проверку.' : 'Нажмите «Обновить статус».'}`;
            delay = Math.max(delay, Math.min(3000 * 2 ** failures, 30000));
            if (failures > 5 && retryNotBefore <= Date.now()) stop();
        } finally {
            browser.clearTimeout(timeout);
            inFlight = false;
            if (!stopped) timer = browser.setTimeout(poll, currentRequest === requestId ? delay : 0);
        }
    };
    browser.addEventListener('pagehide', stop);
    browser.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; failures = 0; poll(); } });
    panel.ownerDocument.addEventListener?.('visibilitychange', () => {
        if (!panel.ownerDocument.hidden) {
            browser.clearTimeout(timer);
            if (failures > 5) { stopped = false; failures = 0; }
            poll();
        }
    });
    timer = browser.setTimeout(poll, 3000);
    return stop;
}

function renderImportHistory(panel, entries) {
    const doc = panel.ownerDocument;
    const services = panel.dataset.showSource === '1';
    const element = (tag, text, className = '') => {
        const node = doc.createElement(tag);
        if (text !== null) node.textContent = text;
        node.className = className;
        return node;
    };
    const rows = entries.map(entry => {
        const row = element('tr', null);
        const file = element('td', null);
        file.append(element('p', entry.filename ?? entry.source_label, 'min-w-48 max-w-sm break-words [overflow-wrap:anywhere]'));
        if (services) file.append(element('p', entry.filename ? entry.source_label : 'Файл ещё не получен', 'mt-1 text-xs text-muted'));
        const status = element('td', null);
        const positive = ['received', 'confirmed', 'received_by_customer', 'approved', 'completed', 'active', 'published'].includes(entry.status);
        const negative = ['cancelled', 'rejected', 'failed', 'blocked'].includes(entry.status);
        const style = negative ? 'bg-danger-surface text-danger ring-danger-border' : positive ? 'bg-accent-surface text-accent ring-success-border' : 'bg-warning-surface text-warning ring-warning-border';
        status.append(element('span', entry.label, `inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${style}`));
        if (services) status.append(element('p', entry.detail, 'mt-1 min-w-48 max-w-sm text-sm text-muted'));
        const action = element('td', null, 'text-right');
        if (entry.url) {
            const link = element('a', 'Открыть', 'inline-flex min-h-10 items-center font-semibold text-accent hover:underline');
            link.href = entry.url;
            action.append(link);
        }
        row.append(file, element('td', entry.supplier), status, element('td', entry.rows ?? '—', 'whitespace-nowrap'), element('td', entry.date, 'whitespace-nowrap'), action);
        return row;
    });
    if (!rows.length) {
        const row = element('tr', null);
        const cell = element('td', null);
        cell.colSpan = 6;
        const empty = element('div', null, 'rounded-panel border border-dashed border-control-border bg-surface px-5 py-10 text-center');
        empty.append(element('p', services ? 'Загрузок из сервисов пока нет' : 'Прайс-листов пока нет', 'font-semibold text-content'), element('p', services ? 'Загрузки из iCorp и PriceList.tj появятся здесь после запуска.' : 'Загруженные файлы появятся здесь.', 'mt-1 text-sm text-muted'));
        cell.append(empty);
        row.append(cell);
        rows.push(row);
    }
    panel.querySelector('[data-progress-entries]').replaceChildren(...rows);
}
