export function createCartTextShare({ prepareText, navigator, onState, isBlocked = () => false }) {
    let version = 0;
    let text = null;
    let busy = false;
    const render = (message) => onState({ busy, ready: text !== null, message });

    return {
        invalidate() {
            version += 1;
            text = null;
            render();
        },
        async activate() {
            if (busy || isBlocked()) return;
            busy = true;
            const current = version;
            const prepared = text;
            render();
            try {
                if (prepared === null) {
                    const data = await prepareText();
                    if (current !== version || isBlocked()) return;
                    text = data.share_text + (data.excluded > 0 ? `\nИсключено недоступных позиций: ${data.excluded}. Они сохранены в корзине.` : '');
                } else {
                    // The second click invokes the browser API before any await.
                    if (navigator.share) {
                        await navigator.share({ title: 'Заявки OAPTEKA', text: prepared });
                    } else if (navigator.clipboard) {
                        await navigator.clipboard.writeText(prepared);
                    } else {
                        throw new Error('Sharing unavailable');
                    }
                    text = null;
                }
            } catch (error) {
                text = null;
                if (error.name !== 'AbortError') {
                    render('Не удалось подготовить или отправить заявку. Обновите корзину и повторите попытку.');
                }
            } finally {
                busy = false;
                render();
            }
        },
    };
}
