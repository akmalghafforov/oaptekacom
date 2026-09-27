export function createSupplierFileRenderer(panel, urls) {
    const buttons = panel.querySelectorAll('[data-file-prepare]');
    const share = panel.querySelector('[data-file-share]');
    const download = panel.querySelector('[data-file-download]');
    const status = panel.querySelector('[data-file-status]');
    const spinner = panel.querySelector('[data-file-spinner]');
    let objectUrl = null;
    const cleanup = () => {
        if (objectUrl) urls.revokeObjectURL(objectUrl);
        objectUrl = null;
        download.removeAttribute('href');
        download.removeAttribute('download');
    };
    return {
        cleanup,
        render(state) {
            if ('busy' in state) {
                buttons.forEach((button) => { button.disabled = state.busy; });
                panel.setAttribute('aria-busy', String(state.busy));
                spinner.hidden = !state.busy;
            }
            if ('ready' in state) {
                share.hidden = !state.ready || !state.supported;
                download.hidden = !state.ready;
                cleanup();
                if (state.ready && state.file) {
                    const label = state.format === 'pdf' ? 'PDF' : 'Excel';
                    share.textContent = `Поделиться ${label} через WhatsApp`;
                    download.textContent = `Скачать ${label}`;
                    objectUrl = urls.createObjectURL(state.file);
                    download.href = objectUrl;
                    download.download = state.file.name;
                }
                status.textContent = '';
            }
            if ('message' in state) status.textContent = state.message;
        },
    };
}
