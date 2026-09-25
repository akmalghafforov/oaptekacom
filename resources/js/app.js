import { initializeCatalogSearch } from './catalog-search.js';

const formatPhone = (value, showCountryCode = false) => {
    let digits = value.replace(/\D/g, '');

    if (digits.startsWith('992')) {
        digits = digits.slice(3);
    }

    digits = digits.slice(0, 9);

    if (digits.length === 0) {
        return showCountryCode ? '+992' : '';
    }

    let formatted = '+992';

    if (digits.length <= 2) {
        return `${formatted} (${digits}`;
    }

    formatted += ` (${digits.slice(0, 2)}) ${digits.slice(2, 5)}`;

    if (digits.length > 5) {
        formatted += `-${digits.slice(5, 7)}`;
    }

    if (digits.length > 7) {
        formatted += `-${digits.slice(7, 9)}`;
    }

    return formatted;
};

const formatSmsCode = (value) => {
    const digits = value.replace(/\D/g, '').slice(0, 6);

    return digits.length > 3 ? `${digits.slice(0, 3)} ${digits.slice(3)}` : digits;
};

const phoneDigits = (value) => {
    let digits = value.replace(/\D/g, '');

    if (digits.startsWith('992')) {
        digits = digits.slice(3);
    }

    return digits.slice(0, 9);
};

const renderPhoneMask = (mask, value) => {
    if (!mask) {
        return;
    }

    const digits = phoneDigits(value);
    const appendDigit = (index) => {
        if (digits[index]) {
            mask.append(digits[index]);

            return;
        }

        const placeholder = document.createElement('span');
        placeholder.className = 'text-slate-400';
        placeholder.textContent = '0';
        mask.append(placeholder);
    };

    mask.replaceChildren('+992 (');
    appendDigit(0);
    appendDigit(1);
    mask.append(') ');
    appendDigit(2);
    appendDigit(3);
    appendDigit(4);
    mask.append('-');
    appendDigit(5);
    appendDigit(6);
    mask.append('-');
    appendDigit(7);
    appendDigit(8);
};

document.querySelectorAll('[data-phone-mask]').forEach((input) => {
    const showCountryCode = input.hasAttribute('data-phone-mask-default');
    const mask = input.parentElement.querySelector('[data-phone-mask-placeholder]');

    input.value = formatPhone(input.value, showCountryCode);
    renderPhoneMask(mask, input.value);
    input.addEventListener('input', () => {
        input.value = formatPhone(input.value, showCountryCode);
        renderPhoneMask(mask, input.value);
    });

    if (showCountryCode && input.value === '+992') {
        requestAnimationFrame(() => {
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        });
    }
});

document.querySelectorAll('[data-sms-code-mask]').forEach((input) => {
    input.value = formatSmsCode(input.value);
    input.addEventListener('input', () => {
        input.value = formatSmsCode(input.value);
    });
    input.form?.addEventListener('submit', () => {
        input.value = input.value.replace(/\D/g, '');
    });
});

document.querySelectorAll('[data-subscription-calculator]').forEach((form) => {
    const plans = form.querySelectorAll('[data-subscription-plan]');
    const terms = form.querySelectorAll('[data-subscription-term]');
    const total = form.querySelector('[data-subscription-total]');
    const daysOutput = form.querySelector('[data-subscription-days]');
    const updateChoices = () => {
        form.querySelectorAll('[data-subscription-choice]').forEach((choice) => {
            const isSelected = choice.querySelector('input').checked;

            choice.classList.toggle('border-brand-600', isSelected);
            choice.classList.toggle('bg-brand-50', isSelected);
            choice.classList.toggle('border-slate-300', !isSelected);
            choice.classList.toggle('bg-white', !isSelected);
        });
    };
    const updateSummary = () => {
        const selectedPlan = form.querySelector('[data-subscription-plan]:checked');
        const selectedTerm = form.querySelector('[data-subscription-term]:checked');
        const dailyPrice = Number(selectedPlan?.dataset.dailyPrice);
        const days = Number(selectedTerm?.dataset.days);

        if (!dailyPrice || !days) {
            total.textContent = '—';
            daysOutput.textContent = 'Выберите тариф и срок подписки.';

            return;
        }

        total.textContent = (dailyPrice * days).toFixed(2);
        daysOutput.textContent = `Подписка будет действовать ${days} дней с даты подтверждения оплаты.`;
    };

    plans.forEach((plan) => plan.addEventListener('change', () => {
        updateChoices();
        updateSummary();
    }));
    terms.forEach((term) => term.addEventListener('change', () => {
        updateChoices();
        updateSummary();
    }));
    form.querySelectorAll('[data-subscription-payment-method]').forEach((method) => method.addEventListener('change', updateChoices));
    updateChoices();
    updateSummary();
});

document.querySelectorAll('[data-catalog-search]').forEach(initializeCatalogSearch);

document.querySelectorAll('[data-drawer]').forEach((drawer) => {
    const openers = document.querySelectorAll(`[aria-controls="${drawer.id}"]`);
    let returnFocus = null;

    const closeDrawer = () => {
        if (drawer.open) {
            drawer.close();
        }
    };

    openers.forEach((opener) => opener.addEventListener('click', () => {
        returnFocus = opener;
        drawer.showModal();
        document.body.classList.add('drawer-open');
        openers.forEach((button) => button.setAttribute('aria-expanded', 'true'));
        drawer.querySelector('[data-drawer-close]')?.focus();
    }));
    drawer.querySelectorAll('[data-drawer-close]').forEach((button) => button.addEventListener('click', closeDrawer));
    drawer.addEventListener('click', (event) => {
        if (event.target === drawer) {
            closeDrawer();
        }
    });
    drawer.addEventListener('close', () => {
        document.body.classList.remove('drawer-open');
        openers.forEach((button) => button.setAttribute('aria-expanded', 'false'));
        returnFocus?.focus();
    });
});

document.querySelectorAll('[data-dialog-open]').forEach((opener) => {
    const dialog = document.getElementById(opener.getAttribute('aria-controls'));

    if (!dialog) {
        return;
    }

    const closeDialog = () => {
        if (dialog.open) {
            dialog.close();
        }
    };

    const openDialog = () => {
        dialog.showModal();
        document.body.classList.add('dialog-open');
        dialog.querySelector('[data-dialog-close]')?.focus();
    };

    opener.addEventListener('click', (event) => {
        const nestedControl = event.target.closest('a, button, input, select, textarea, label');

        if (nestedControl && nestedControl !== opener) {
            return;
        }

        openDialog();
    });
    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', closeDialog));
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            closeDialog();
        }
    });
    dialog.addEventListener('close', () => {
        document.body.classList.remove('dialog-open');
        opener.focus();
    });
});

document.querySelectorAll('[data-dialog-auto-open]').forEach((dialog) => {
    dialog.showModal();
    document.body.classList.add('dialog-open');
    dialog.querySelector('input')?.focus();
});

document.querySelectorAll('[data-contact-toggle]').forEach((toggle) => {
    const details = document.getElementById(toggle.getAttribute('aria-controls'));

    if (!details) {
        return;
    }

    toggle.addEventListener('click', () => {
        const isExpanded = toggle.getAttribute('aria-expanded') === 'true';

        details.hidden = isExpanded;
        toggle.setAttribute('aria-expanded', String(!isExpanded));
        toggle.textContent = isExpanded ? toggle.dataset.showLabel : toggle.dataset.hideLabel;
    });
});

document.querySelectorAll('[data-quantity-stepper]').forEach((stepper) => {
    const input = stepper.querySelector('[data-quantity-input]');

    stepper.querySelectorAll('[data-quantity-step]').forEach((button) => button.addEventListener('click', () => {
        const minimum = Number(input.min || 1);
        const maximum = Number(input.max || 999);
        const nextValue = Math.max(minimum, Math.min(maximum, Number(input.value || minimum) + Number(button.dataset.quantityStep)));

        input.value = String(nextValue);
        input.focus();
    }));
});

document.querySelectorAll('[data-supplier-share]').forEach((button) => button.addEventListener('click', async () => {
    const text = button.dataset.shareText;
    let sharedVia = null;

    try {
        if (navigator.share) {
            await navigator.share({ title: 'Заявка OAPTEKA', text });
            sharedVia = 'native';
        } else if (navigator.clipboard) {
            await navigator.clipboard.writeText(text);
            sharedVia = 'clipboard';
        }
    } catch (error) {
        return;
    }

    if (! sharedVia) {
        return;
    }

    button.disabled = true;

    try {
        const response = await fetch(button.dataset.shareUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': button.dataset.csrfToken,
            },
            body: new URLSearchParams({ shared_via: sharedVia }),
        });

        if (! response.ok) {
            throw new Error('Unable to save the supplier request.');
        }

        window.location.assign(response.url);
    } catch (error) {
        button.disabled = false;
        window.alert('Не удалось сохранить заявку. Позиции остались в корзине.');
    }
}));
