export function updateCartAvailability(root, data) {
    data.availability.forEach((item) => {
        root.querySelectorAll(`[data-cart-availability-warning="${item.id}"]`).forEach((element) => {
            element.textContent = item.warning ?? '';
            element.hidden = item.warning === null;
        });
    });
    root.querySelectorAll('[data-cart-exclusion-warning]').forEach((element) => {
        element.hidden = data.cart.excluded_count === 0;
    });
}
