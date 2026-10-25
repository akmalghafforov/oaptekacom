export function initializeDropdown(root) {
    const trigger = root.querySelector('[data-dropdown-trigger]');
    const menu = root.querySelector('[data-dropdown-menu]');
    const items = () => [...menu.querySelectorAll('[role="menuitem"]')].filter((item) => !item.disabled);
    const close = (restore = false) => {
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        if (restore) trigger.focus();
    };
    const open = (last = false) => {
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        const choices = items();
        (last ? choices.at(-1) : choices[0])?.focus();
    };
    trigger.addEventListener('click', () => menu.hidden ? open() : close(true));
    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !menu.hidden) {
            event.preventDefault();
            close(true);
        } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            if (menu.hidden) {
                open(event.key === 'ArrowUp' || event.key === 'End');
                return;
            }
            const choices = items();
            const index = choices.indexOf(root.ownerDocument.activeElement);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? choices.length - 1
                : (index + (event.key === 'ArrowDown' ? 1 : -1) + choices.length) % choices.length;
            choices[next]?.focus();
        } else if (event.key === 'Tab') {
            close();
        }
    });
    menu.addEventListener('click', (event) => {
        if (event.target.closest('[role="menuitem"]')) close(true);
    });
    root.ownerDocument.addEventListener('click', (event) => {
        if (!root.contains(event.target) && !menu.hidden) close();
    });
    root.addEventListener('focusout', (event) => {
        if (!root.contains(event.relatedTarget)) close();
    });
}
