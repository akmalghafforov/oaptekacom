import test from 'node:test';
import assert from 'node:assert/strict';
import { initializePromotionSelection, initializePromotionTerms, sharePromotion } from '../../resources/js/promotions.js';

function termsForm(version) {
    let submit;
    const form = { querySelector: () => ({ value: version }), addEventListener: (type, action) => { submit = action; }, setAttribute() {} };
    initializePromotionTerms(form);
    return (prevented = false) => {
        let blocked = prevented;
        submit({ defaultPrevented: prevented, preventDefault: () => { blocked = true; } });
        return blocked;
    };
}

test('terms acceptance carries one specific version and guards repeated submission', () => {
    const submit = termsForm('a'.repeat(64));
    assert.equal(submit(), false);
    assert.equal(submit(), true);
    assert.equal(termsForm('')(), true);
    assert.equal(termsForm('old-version')(), true);
});

test('terms acceptance waits for quantity-save resubmission without consuming confirmation early', () => {
    const submit = termsForm('b'.repeat(64));
    assert.equal(submit(true), true);
    assert.equal(submit(), false);
});

test('selected order uses only checked items with their current quantities', () => {
    let submit;
    let selected = [];
    const generated = [];
    const error = { hidden: true };
    const document = {
        querySelectorAll: () => selected,
        querySelector: () => ({ value: '7' }),
        createElement: () => ({ dataset: {}, remove() { generated.splice(generated.indexOf(this), 1); } }),
    };
    const form = { addEventListener: (type, action) => { submit = action; }, querySelector: () => error, querySelectorAll: () => [...generated], append: (input) => generated.push(input) };
    initializePromotionSelection(form, document);
    let blocked = false;
    submit({ preventDefault: () => { blocked = true; } });
    assert.equal(blocked, true);
    assert.equal(error.hidden, false);
    selected = [{ value: '10' }, { value: '30' }];
    submit({ preventDefault: () => assert.fail() });
    assert.equal(error.hidden, true);
    assert.deepEqual(generated.map(({ name, value }) => [name, value]), [['items[0][id]', '10'], ['items[0][quantity]', '7'], ['items[1][id]', '30'], ['items[1][quantity]', '7']]);
    selected = [{ value: '30' }];
    submit({ preventDefault: () => assert.fail() });
    assert.equal(generated.length, 2);
    assert.equal(generated[0].value, '30');
});

test('sharing uses the system menu with clipboard fallback', async () => {
    const button = { dataset: { title: 'Акция', url: 'https://example.test/promotions/1' } };
    const status = {};
    let shared;
    await sharePromotion(button, { share: async (value) => { shared = value; } }, status);
    assert.deepEqual(shared, { title: 'Акция', url: button.dataset.url });
    let copied;
    await sharePromotion(button, { clipboard: { writeText: async (value) => { copied = value; } } }, status);
    assert.equal(copied, button.dataset.url);
    copied = null;
    await sharePromotion(button, { share: async () => { throw new Error(); }, clipboard: { writeText: async (value) => { copied = value; } } }, status);
    assert.equal(copied, button.dataset.url);
});

test('cancelled system sharing does not copy a link unexpectedly', async () => {
    await sharePromotion({ dataset: { title: 'Акция', url: '/promotions/1' } }, { share: async () => { throw Object.assign(new Error(), { name: 'AbortError' }); }, clipboard: { writeText: () => assert.fail() } }, {});
});
