export function createSupplierFileShare({ flush, fetchFile, navigator, makeFile, onState }) {
    let version = 0;
    let file = null;
    let busy = false;
    let format = null;
    const invalidate = () => {
        version += 1;
        file = null;
        onState({ ready: false, busy, format, message: busy ? `Готовим ${format === 'pdf' ? 'PDF' : 'Excel'}…` : 'Количество изменилось. Подготовьте PDF или Excel ещё раз.' });
    };
    return {
        invalidate,
        async prepare(url, selectedFormat) {
            if (busy) return;
            busy = true;
            format = selectedFormat;
            invalidate();
            const current = version;

            try {
                await flush();
                const response = await fetchFile(url);
                const mime = format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                if (!response.ok || !response.headers.get('Content-Type')?.includes(mime)) throw new Error();
                const blob = await response.blob();
                if (current !== version) {
                    onState({ ready: false, format, message: 'Количество изменилось. Подготовьте PDF или Excel ещё раз.' });
                    return;
                }
                const name = response.headers.get('Content-Disposition')?.match(/filename="?([^";]+)"?/)?.[1] ?? `oapteka.${format === 'pdf' ? 'pdf' : 'xlsx'}`;
                file = makeFile(blob, name, mime);
                let supported = false;
                try { supported = Boolean(navigator.share && navigator.canShare?.({ files: [file] })); } catch {}
                onState({ ready: true, format, file, supported, message: supported ? 'Выберите WhatsApp в меню «Поделиться файлом».' : 'Скачайте файл и прикрепите его вручную в чате WhatsApp.' });
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
