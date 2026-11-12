export function renderImportProgress(panel, entries) {
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
