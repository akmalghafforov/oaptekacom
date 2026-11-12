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
            link.textContent = 'Открыть подробности';
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

export function initializeImportProgress(panel, browser, render = renderImportProgress) {
    let stopped = false;
    let polls = 0;
    let timer;
    const feedback = panel.querySelector('[data-progress-feedback]');
    const stop = () => { stopped = true; browser.clearTimeout(timer); };
    const poll = async () => {
        if (stopped) return;
        if (panel.ownerDocument.hidden) { timer = browser.setTimeout(poll, 3000); return; }
        try {
            const response = await browser.fetch(panel.dataset.statusUrl, {headers: {Accept: 'application/json'}, cache: 'no-store'});
            if (!response.ok) throw new Error('status');
            const result = await response.json();
            if (stopped) return;
            render(panel, result.entries);
            if (panel.dataset.watchNew !== '1' && !result.entries.some(entry => entry.active)) {
                feedback.textContent = 'Статус обновлён. Автообновление завершено.';
                stop();
                return;
            }
            if (++polls >= 200) {
                feedback.textContent = 'Автообновление остановлено. Нажмите «Обновить статус».';
                stop();
                return;
            }
            timer = browser.setTimeout(poll, 3000);
        } catch {
            if (stopped) return;
            feedback.textContent = 'Не удалось обновить статус. Нажмите «Обновить статус».';
            stop();
        }
    };
    browser.addEventListener('pagehide', stop, {once: true});
    browser.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; polls = 0; poll(); } });
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
