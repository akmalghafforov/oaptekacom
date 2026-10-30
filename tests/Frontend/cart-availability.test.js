import test from 'node:test';
import assert from 'node:assert/strict';
import { updateCartAvailability } from '../../resources/js/cart-availability.js';

test('stock recovery clears the line warning and hides the exclusion notice only when all lines are eligible', () => {
    const line = { hidden: false, textContent: 'Недостаточный остаток' };
    const notice = { hidden: false };
    const root = { querySelectorAll: (selector) => selector === '[data-cart-exclusion-warning]' ? [notice] : [line] };
    updateCartAvailability(root, { availability: [{ id: 1, warning: null }], cart: { excluded_count: 1 } });
    assert.equal(line.hidden, true);
    assert.equal(line.textContent, '');
    assert.equal(notice.hidden, false);
    updateCartAvailability(root, { availability: [{ id: 1, warning: null }], cart: { excluded_count: 0 } });
    assert.equal(notice.hidden, true);
    updateCartAvailability(root, { availability: [{ id: 1, warning: 'Недостаточный остаток' }], cart: { excluded_count: 1 } });
    assert.equal(line.hidden, false);
    assert.equal(line.textContent, 'Недостаточный остаток');
    assert.equal(notice.hidden, false);
});
