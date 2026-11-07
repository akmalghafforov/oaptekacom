const months = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

const utcDate = (year, month, day) => {
    const date = new Date(0);
    date.setUTCFullYear(year, month, day);
    return date;
};
const isoDate = (date) => `${String(date.getUTCFullYear()).padStart(4, '0')}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;

export function parseDate(value) {
    const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    const local = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(value);
    if (!iso && !local) return null;
    const [year, month, day] = iso ? iso.slice(1).map(Number) : [Number(local[3]), Number(local[2]), Number(local[1])];
    const date = utcDate(year, month - 1, day);
    return year >= 1 && year <= 9999 && date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day ? isoDate(date) : null;
}

export function displayDate(value) {
    return parseDate(value)?.split('-').reverse().join('.') ?? '';
}

export function calendarDays(year, month) {
    const first = utcDate(year, month, 1);
    const offset = (first.getUTCDay() + 6) % 7;
    return Array.from({ length: 42 }, (_, index) => isoDate(utcDate(year, month, 1 - offset + index)));
}

export function moveDate(value, amount, unit = 'day') {
    const date = new Date(`${value}T00:00:00Z`);
    if (unit === 'month') {
        const target = utcDate(date.getUTCFullYear(), date.getUTCMonth() + amount, 1);
        const last = utcDate(target.getUTCFullYear(), target.getUTCMonth() + 1, 0).getUTCDate();
        target.setUTCDate(Math.min(date.getUTCDate(), last));
        return isoDate(target);
    }
    date.setUTCDate(date.getUTCDate() + amount);
    return isoDate(date);
}

export function dateAllowed(value, min, max) {
    return Boolean(parseDate(value)) && (!min || value >= min) && (!max || value <= max);
}

export function dateBounds(input, linkedName) {
    const linked = [...(input.form?.elements ?? [])].find((control) => control.name === linkedName)?.value;
    const minimums = [parseDate(input.min), parseDate(linked ?? '')].filter(Boolean).sort();
    return [minimums.at(-1) ?? null, parseDate(input.max)];
}

export function initializeDatePicker(root, environment = window) {
    const document = root.ownerDocument;
    const input = root.querySelector('[data-date-input]');
    const toggle = root.querySelector('[data-date-toggle]');
    const panel = root.querySelector('[data-date-panel]');
    const grid = root.querySelector('[data-date-grid]');
    const month = root.querySelector('[data-date-month]');
    const year = root.querySelector('[data-date-year]');
    const heading = root.querySelector('[data-date-heading]');
    const todayButton = root.querySelector('[data-date-today]');
    const previous = root.querySelector('[data-date-prev]');
    const next = root.querySelector('[data-date-next]');
    const form = input.form;
    if (input.disabled || input.readOnly) return;
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dushanbe', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    const canonical = document.createElement('input');
    canonical.type = 'hidden';
    canonical.name = input.name;
    canonical.value = canonical.defaultValue = input.value;
    root.append(canonical);
    input.removeAttribute('name');
    input.type = 'text';
    input.placeholder = 'дд.мм.гггг';
    input.inputMode = 'numeric';
    input.autocomplete = 'off';
    input.classList.add('pr-12');
    input.value = displayDate(canonical.value);
    input.setAttribute('aria-haspopup', 'dialog');
    input.setAttribute('aria-controls', panel.id);
    input.setAttribute('aria-expanded', 'false');
    toggle.hidden = false;
    month.removeAttribute('name');
    year.removeAttribute('name');
    let focused = canonical.value || today;
    let visible = focused.slice(0, 7);

    const bounds = () => dateBounds(input, root.dataset.dateMinField);
    const allowed = (date) => dateAllowed(date, ...bounds());
    const validate = () => {
        const parsed = parseDate(input.value);
        canonical.value = parsed ?? '';
        input.setCustomValidity(input.value && !parsed ? 'Укажите дату в формате дд.мм.гггг.'
            : parsed && !allowed(parsed) ? 'Выберите дату в допустимом периоде.' : '');
    };
    const close = (restore = false) => {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-expanded', 'false');
        if (restore) toggle.focus();
    };
    const position = () => {
        if (panel.hidden) return;
        const rect = input.getBoundingClientRect();
        const width = panel.offsetWidth;
        const height = panel.offsetHeight;
        const below = environment.innerHeight - rect.bottom;
        const top = below >= height + 8 || below > rect.top ? rect.bottom + 6 : rect.top - height - 6;
        panel.style.left = `${Math.max(8, Math.min(rect.left, environment.innerWidth - width - 8))}px`;
        panel.style.top = `${Math.max(8, Math.min(top, environment.innerHeight - height - 8))}px`;
    };
    const option = (value, text) => {
        const item = document.createElement('option');
        item.value = String(value);
        item.textContent = text;
        return item;
    };
    month.replaceChildren(...months.map((name, index) => option(index, name)));
    const render = (focus = false) => {
        const activeDay = grid.querySelector('[data-date]:focus')?.dataset.date;
        if (activeDay && !focus) focused = activeDay;
        const [visibleYear, visibleMonth] = visible.split('-').map(Number);
        month.value = String(visibleMonth - 1);
        year.replaceChildren(...Array.from({ length: Math.min(9999, visibleYear + 10) - Math.max(1, visibleYear - 10) + 1 }, (_, i) => {
            const value = Math.max(1, visibleYear - 10) + i;
            return option(value, String(value));
        }));
        year.value = String(visibleYear);
        heading.textContent = `${months[visibleMonth - 1]} ${visibleYear}`;
        grid.setAttribute('aria-label', heading.textContent);
        grid.replaceChildren();
        const days = calendarDays(visibleYear, visibleMonth - 1);
        const availableFocus = days.includes(focused) && allowed(focused) ? focused : days.find((day) => day.startsWith(visible) && allowed(day));
        for (let row = 0; row < 6; row++) {
            const week = document.createElement('div');
            week.setAttribute('role', 'row');
            week.className = 'grid grid-cols-7';
            for (const day of days.slice(row * 7, row * 7 + 7)) {
                const cell = document.createElement('div');
                cell.setAttribute('role', 'gridcell');
                cell.setAttribute('aria-selected', String(day === canonical.value));
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.date = day;
                button.textContent = String(Number(day.slice(-2)));
                button.disabled = !allowed(day);
                button.tabIndex = day === availableFocus ? 0 : -1;
                button.setAttribute('aria-label', new Intl.DateTimeFormat('ru-RU', { dateStyle: 'full', timeZone: 'UTC' }).format(new Date(`${day}T00:00:00Z`)));
                if (day === today) button.setAttribute('aria-current', 'date');
                button.className = 'flex min-h-10 w-full items-center justify-center rounded-control text-sm font-medium transition focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-30 '
                    + (day === canonical.value ? 'bg-action font-bold text-white hover:bg-action-hover'
                        : day === today ? 'bg-accent-surface text-accent ring-1 ring-inset ring-success-border hover:bg-accent-surface-strong'
                            : day.startsWith(visible) ? 'text-heading hover:bg-surface-hover' : 'text-muted hover:bg-surface-hover');
                cell.append(button);
                week.append(cell);
            }
            grid.append(week);
        }
        previous.disabled = visible === '0001-01';
        next.disabled = visible === '9999-12';
        todayButton.disabled = !allowed(today);
        if (focus || activeDay) grid.querySelector(`[data-date="${availableFocus}"]`)?.focus();
        position();
    };
    const open = (focus = false) => {
        focused = parseDate(input.value) || bounds()[0] || today;
        visible = focused.slice(0, 7);
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-expanded', 'true');
        render(focus);
    };
    const select = (value) => {
        input.value = displayDate(value);
        validate();
        canonical.dispatchEvent(new Event('change', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        close(true);
    };
    toggle.addEventListener('click', () => panel.hidden ? open(true) : close(true));
    input.addEventListener('click', () => open());
    input.addEventListener('input', () => {
        validate();
        canonical.dispatchEvent(new Event('change', { bubbles: true }));
        if (!panel.hidden) render();
    });
    grid.addEventListener('click', (event) => {
        const button = event.target.closest('[data-date]');
        if (button && !button.disabled) select(button.dataset.date);
    });
    grid.addEventListener('keydown', (event) => {
        const current = event.target.closest('[data-date]')?.dataset.date;
        if (!current) return;
        const offset = (new Date(`${current}T00:00:00Z`).getUTCDay() + 6) % 7;
        const offsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7, Home: -offset, End: 6 - offset };
        let target;
        if (Object.hasOwn(offsets, event.key)) target = moveDate(current, offsets[event.key]);
        if (event.key === 'PageUp' || event.key === 'PageDown') target = moveDate(current, (event.key === 'PageUp' ? -1 : 1) * (event.shiftKey ? 12 : 1), 'month');
        if (!target) return;
        event.preventDefault();
        if (!allowed(target)) return;
        focused = target;
        visible = target.slice(0, 7);
        render(true);
    });
    const changeMonth = (offset) => {
        visible = moveDate(`${visible}-01`, offset, 'month').slice(0, 7);
        focused = `${visible}-01`;
        render();
    };
    previous.addEventListener('click', () => changeMonth(-1));
    next.addEventListener('click', () => changeMonth(1));
    const changePeriod = () => {
        visible = `${String(year.value).padStart(4, '0')}-${String(Number(month.value) + 1).padStart(2, '0')}`;
        focused = `${visible}-01`;
        render();
    };
    month.addEventListener('change', changePeriod);
    year.addEventListener('change', changePeriod);
    todayButton.addEventListener('click', () => { if (allowed(today)) select(today); });
    root.querySelector('[data-date-clear]').addEventListener('click', () => select(''));
    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); close(true); }
        if (event.target === input && event.key === 'ArrowDown') { event.preventDefault(); open(true); }
    });
    root.addEventListener('focusout', () => environment.setTimeout(() => { if (!root.contains(document.activeElement)) close(); }, 0));
    document.addEventListener('pointerdown', (event) => { if (!root.contains(event.target)) close(); });
    environment.addEventListener('resize', position);
    environment.addEventListener('scroll', position, true);
    const refresh = (event) => {
        validate();
        if (!panel.hidden && (!event || event.target.name === root.dataset.dateMinField)) render();
    };
    form?.addEventListener('change', refresh);
    form?.addEventListener('reset', () => environment.setTimeout(() => { input.value = displayDate(canonical.defaultValue); refresh(); close(); }, 0));
    validate();
    return { open, close, value: () => canonical.value };
}
