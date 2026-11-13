export function initializeBulkSubscriptions(root) {
    const selectPage = root.querySelector('[data-select-page]');
    const count = root.querySelector('[data-selected-count]');
    const opener = root.querySelector('[data-bulk-open]');
    const apply = root.querySelector('[data-bulk-apply]');
    const checkboxes = [...root.querySelectorAll('[data-account-checkbox]')];
    const inputs = [...root.querySelectorAll('[data-selected-account]')];
    const selectedIds = new Set(checkboxes.filter((checkbox) => checkbox.checked).map((checkbox) => checkbox.value));
    const accountIds = new Set(checkboxes.map((checkbox) => checkbox.value));
    const update = () => {
        checkboxes.forEach((checkbox) => { checkbox.checked = selectedIds.has(checkbox.value); });
        inputs.forEach((input) => { input.disabled = !selectedIds.has(input.value); });
        count.textContent = String(selectedIds.size);
        opener.disabled = selectedIds.size === 0;
        apply.disabled = selectedIds.size === 0;
        selectPage.checked = accountIds.size > 0 && selectedIds.size === accountIds.size;
        selectPage.indeterminate = selectedIds.size > 0 && selectedIds.size < accountIds.size;
        selectPage.disabled = accountIds.size === 0;
    };
    const clear = () => {
        selectedIds.clear();
        update();
    };
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', () => {
        if (checkbox.checked) {
            selectedIds.add(checkbox.value);
        } else {
            selectedIds.delete(checkbox.value);
        }
        update();
    }));
    selectPage.addEventListener('change', () => {
        selectedIds.clear();
        if (selectPage.checked) {
            accountIds.forEach((id) => selectedIds.add(id));
        }
        update();
    });
    root.ownerDocument.querySelector('[data-subscription-filters]')?.addEventListener('change', clear);
    root.ownerDocument.defaultView.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            clear();
        }
    });
    update();
}
