export function createSupplierFileShare({ flush, fetchFile, navigator, makeFile, onState }) {
    let version = 0;
    let file = null;
    let busy = false;
    let format = null;
    const files = new Map();
    const readyState = (entry, selectedFormat) => {
        let supported = false;
        try { supported = Boolean(navigator.share && navigator.canShare?.({ files: [entry] })); } catch {}
        onState({ ready: true, format: selectedFormat, file: entry, supported, message: supported ? 'Файл готов. Выберите WhatsApp в меню отправки.' : 'Файл готов. Скачайте файл и прикрепите его вручную в чате WhatsApp.' });
    };
    const invalidate = () => {
        version += 1;
        const hadPreparedFile = files.size > 0;
        files.clear();
        file = null;
        if (hadPreparedFile) {
            onState({ ready: false, busy, format, message: busy ? `Готовим ${format === 'pdf' ? 'PDF' : 'Excel'}…` : 'Количество изменилось. Подготовьте PDF или Excel ещё раз.' });
        }
    };
    return {
        invalidate,
        async prepare(url, selectedFormat) {
            if (busy) return;
            format = selectedFormat;
            busy = true;
            onState({ busy: true, ready: false, format, message: `Готовим ${format === 'pdf' ? 'PDF' : 'Excel'}…` });
            const current = version;

            try {
                await flush();
                if (files.has(format)) {
                    file = files.get(format);
                    readyState(file, format);
                    return;
                }
                const response = await fetchFile(url);
                const mime = format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                if (!response.ok || !response.headers.get('Content-Type')?.includes(mime)) throw new Error();
                const blob = await response.blob();
                if (current !== version) {
                    onState({ ready: false, format, message: 'Количество изменилось во время подготовки. Подготовьте PDF или Excel ещё раз.' });
                    return;
                }
                const name = response.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/)?.[1] ?? `oapteka.${format === 'pdf' ? 'pdf' : 'xlsx'}`;
                file = makeFile(blob, name, mime);
                files.set(format, file);
                readyState(file, format);
            } catch {
                onState({ ready: false, message: 'Не удалось подготовить файл. Проверьте количество и нажмите PDF или Excel ещё раз.' });
            } finally {
                busy = false;
                onState({ busy: false });
            }
        },
        async share() {
            if (!file) return;
            try {
                await navigator.share({ files: [file] });
            } catch (error) {
                if (error?.name !== 'AbortError') onState({ message: 'Не удалось открыть меню отправки. Скачайте файл и прикрепите его в WhatsApp.' });
            }
        },
    };
}
