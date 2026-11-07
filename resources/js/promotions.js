export function initializePromotionSelection(form, document) {
    form.addEventListener('submit', (event) => {
        form.querySelectorAll('[data-generated-selection]').forEach((input) => input.remove());
        const selected = [...document.querySelectorAll('[data-promotion-selection]:checked')];
        const error = form.querySelector('[data-promotion-selection-error]');
        error.hidden = selected.length > 0;
        if (!selected.length) {
            event.preventDefault();
            return;
        }
        selected.forEach((checkbox, index) => {
            const quantity = document.querySelector(`[data-promotion-quantity="${checkbox.value}"]`);
            for (const [key, value] of Object.entries({ id: checkbox.value, quantity: quantity.value })) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `items[${index}][${key}]`;
                input.value = value;
                input.dataset.generatedSelection = '';
                form.append(input);
            }
        });
    });
}

export async function sharePromotion(button, navigator, status) {
    try {
        if (navigator.share) {
            await navigator.share({ title: button.dataset.title, url: button.dataset.url });
            status.textContent = 'Ссылка отправлена.';
        } else {
            await navigator.clipboard.writeText(button.dataset.url);
            status.textContent = 'Ссылка скопирована.';
        }
    } catch (error) {
        if (error.name === 'AbortError') return;
        try {
            await navigator.clipboard.writeText(button.dataset.url);
            status.textContent = 'Ссылка скопирована.';
        } catch {
            status.textContent = `Скопируйте ссылку: ${button.dataset.url}`;
        }
    }
}

export function initializePromotionTerms(form) {
    let submitting = false;
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        const version = form.querySelector('[name="terms_version"]')?.value;
        if (submitting || !/^[a-f0-9]{64}$/.test(version ?? '')) {
            event.preventDefault();
            return;
        }
        submitting = true;
        form.setAttribute('aria-busy', 'true');
    });
}
