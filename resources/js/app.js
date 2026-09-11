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
