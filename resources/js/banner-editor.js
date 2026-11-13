export function cropRectangle(sourceWidth, sourceHeight, targetWidth, targetHeight, zoom, horizontal, vertical) {
    const scale = Math.max(targetWidth / sourceWidth, targetHeight / sourceHeight) * zoom;
    const width = targetWidth / scale;
    const height = targetHeight / scale;
    return { x: (sourceWidth - width) * horizontal, y: (sourceHeight - height) * vertical, width, height };
}

export function initializeBannerEditor(form) {
    const status = form.querySelector('[data-crop-status]');
    const save = form.querySelector('[data-banner-save]');
    let submitting = false;
    let pending = 0;
    const devices = [...form.querySelectorAll('[data-crop-device]')].map((root) => {
        const canvas = root.querySelector('canvas');
        const output = root.querySelector('[data-crop-output]');
        let image = null;
        let generation = 0;
        let errorMessage = '';
        const draw = () => {
            if (!image) return;
            const zoom = Number(root.querySelector('[data-crop-zoom]').value);
            const { x, y, width, height } = cropRectangle(image.width, image.height, canvas.width, canvas.height, zoom,
                Number(root.querySelector('[data-crop-x]').value), Number(root.querySelector('[data-crop-y]').value));
            const context = canvas.getContext('2d');
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(image, x, y, width, height, 0, 0, canvas.width, canvas.height);
        };
        const load = async (file) => {
            if (!file) return;
            const version = ++generation;
            pending++;
            save.disabled = true;
            const url = URL.createObjectURL(file);
            try {
                if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 15 * 1024 * 1024) throw new Error('Выберите JPEG, PNG или WebP до 15 МБ.');
                const source = new Image();
                source.src = url;
                await source.decode();
                if (source.width * source.height > 40000000) throw new Error('Изображение слишком большое: максимум 40 мегапикселей.');
                if (version !== generation) return;
                image = source;
                errorMessage = '';
                root.querySelector('[data-crop-existing]')?.setAttribute('hidden', '');
                root.querySelector('[data-crop-controls]').hidden = false;
                canvas.hidden = false;
                root.querySelector('[data-crop-zoom]').value = '1';
                root.querySelector('[data-crop-x]').value = '0.5';
                root.querySelector('[data-crop-y]').value = '0.5';
                draw();
            } catch (error) {
                if (version === generation) errorMessage = error.message || 'Не удалось открыть изображение.';
            } finally {
                URL.revokeObjectURL(url);
                pending--;
                save.disabled = pending > 0 || devices.some((device) => device.isInvalid());
                status.textContent = [...new Set(devices.map((device) => device.error()).filter(Boolean))].join(' ');
            }
        };
        const sourceInput = root.querySelector('[data-crop-source]');
        sourceInput.removeAttribute('name');
        sourceInput.addEventListener('change', () => load(sourceInput.files[0]));
        root.querySelectorAll('input[type="range"]').forEach((input) => {
            input.removeAttribute('name');
            input.addEventListener('input', draw);
        });
        return {
            load,
            isInvalid: () => Boolean(errorMessage),
            error: () => errorMessage,
            async export() {
                if (!image) return;
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
                if (!blob || blob.size > 5 * 1024 * 1024) throw new Error('Не удалось сохранить кадр до 5 МБ. Выберите другое изображение.');
                const transfer = new DataTransfer();
                transfer.items.add(new File([blob], `${root.dataset.cropDevice}.jpg`, { type: 'image/jpeg' }));
                output.files = transfer.files;
            },
        };
    });
    const sourceAll = form.querySelector('[data-banner-source-all]');
    sourceAll.removeAttribute('name');
    sourceAll.addEventListener('change', () => devices.forEach((device) => device.load(sourceAll.files[0])));
    form.addEventListener('submit', async (event) => {
        if (submitting) return;
        event.preventDefault();
        if (pending || devices.some((device) => device.isInvalid())) return;
        save.disabled = true;
        try {
            await Promise.all(devices.map((device) => device.export()));
            submitting = true;
            form.requestSubmit();
        } catch (error) { status.textContent = error.message; }
        finally { save.disabled = false; }
    });
}
