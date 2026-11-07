export async function initializePromotionPdf(root, pdfjs, environment = globalThis) {
    const canvas = root.querySelector('[data-pdf-canvas]');
    const output = root.querySelector('[data-pdf-page]');
    const error = root.querySelector('[data-pdf-error]');
    const container = root.querySelector('[data-pdf-container]');
    const controls = Object.fromEntries(['prev', 'next', 'in', 'out', 'fit'].map((name) => [name, root.querySelector(`[data-pdf-${name}]`)]));
    let pdf;
    let pageNumber = 1;
    let scale = 1;
    let fit = true;
    let disposed = false;
    let queue = Promise.resolve();
    const loading = pdfjs.getDocument({ url: root.dataset.url, withCredentials: true, isEvalSupported: false });

    const refreshControls = (busy) => {
        for (const button of Object.values(controls)) button.disabled = busy || !pdf || disposed;
        controls.prev.disabled ||= pageNumber <= 1;
        controls.next.disabled ||= !pdf || pageNumber >= pdf.numPages;
        root.setAttribute('aria-busy', String(busy));
    };
    const render = () => {
        refreshControls(true);
        queue = queue.then(async () => {
            if (disposed) return;
            const page = await pdf.getPage(pageNumber);
            if (disposed) return;
            if (fit) scale = Math.max(0.1, Math.min(4, (container.clientWidth || 600) / page.getViewport({ scale: 1 }).width));
            const viewport = page.getViewport({ scale });
            const ratio = Math.min(2, environment.devicePixelRatio || 1);
            canvas.width = Math.floor(viewport.width * ratio);
            canvas.height = Math.floor(viewport.height * ratio);
            canvas.style.width = `${Math.floor(viewport.width)}px`;
            canvas.style.height = `${Math.floor(viewport.height)}px`;
            await page.render({ canvasContext: canvas.getContext('2d'), viewport, transform: ratio === 1 ? null : [ratio, 0, 0, ratio, 0, 0] }).promise;
            output.textContent = `${pageNumber} / ${pdf.numPages} · ${Math.round(scale * 100)}%`;
            canvas.setAttribute('aria-label', `PDF, страница ${pageNumber} из ${pdf.numPages}`);
            error.hidden = true;
        }).catch(() => {
            error.hidden = false;
            output.textContent = 'Ошибка просмотра';
        }).finally(() => refreshControls(false));
        return queue;
    };
    const change = (action) => {
        if (disposed || !pdf || root.getAttribute('aria-busy') === 'true') return queue;
        action();
        return render();
    };
    const actions = {
        prev: () => change(() => { pageNumber = Math.max(1, pageNumber - 1); }),
        next: () => change(() => { pageNumber = Math.min(pdf.numPages, pageNumber + 1); }),
        in: () => change(() => { fit = false; scale = Math.min(4, scale + 0.25); }),
        out: () => change(() => { fit = false; scale = Math.max(0.25, scale - 0.25); }),
        fit: () => change(() => { fit = true; }),
    };
    Object.entries(controls).forEach(([name, button]) => button.addEventListener('click', actions[name]));
    refreshControls(true);
    try {
        pdf = await loading.promise;
        await render();
    } catch {
        output.textContent = 'Ошибка просмотра';
        error.hidden = false;
        refreshControls(false);
    }
    const dispose = () => {
        disposed = true;
        refreshControls(false);
        queue.finally(() => loading.destroy());
    };
    environment.addEventListener?.('pagehide', dispose, { once: true });
    return { ...actions, dispose };
}
