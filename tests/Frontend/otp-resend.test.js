import test from 'node:test';
import assert from 'node:assert/strict';
import { resendPresentation, initializeOtpResend } from '../../resources/js/otp-resend.js';

const state = { send_count: 1, server_time: 1000000, resend_available_at: 1060000, allowance_resets_at: 2800000 };

test('countdown uses ceiling rounding and enables exactly at the deadline', () => {
    assert.equal(resendPresentation(state, 1000000).label, 'Отправить код повторно через 01:00');
    assert.equal(resendPresentation(state, 1001000).label, 'Отправить код повторно через 00:59');
    assert.equal(resendPresentation(state, 1059999).label, 'Отправить код повторно через 00:01');
    assert.deepEqual(resendPresentation(state, 1060000), { exhausted: false, disabled: false, label: 'Отправить код повторно' });
});

test('exhaustion persists after cooldown and ends exactly at allowance reset', () => {
    const exhausted = { ...state, send_count: 3 };
    assert.equal(resendPresentation(exhausted, 1060000).disabled, true);
    assert.equal(resendPresentation(exhausted, 2799999).exhausted, true);
    assert.deepEqual(resendPresentation(exhausted, 2800000), { exhausted: false, disabled: false, label: 'Отправить код повторно' });
});

for (const event of ['interval', 'visibilitychange', 'pageshow']) {
    test(`${event} accounts for suspended time and a different client clock`, (t) => {
        let time = 500000000;
        t.mock.method(Date, 'now', () => time);
        const callbacks = {};
        const button = {};
        const fallback = {};
        const root = {
            dataset: { otpResend: JSON.stringify(state) },
            querySelector: (selector) => selector === '[data-otp-resend-button]' ? button : fallback,
        };
        const document = { addEventListener: (name, callback) => { callbacks[name] = callback; } };
        const window = {
            setInterval: (callback) => { callbacks.interval = callback; },
            addEventListener: (name, callback) => { callbacks[name] = callback; },
        };
        initializeOtpResend(root, document, window);
        assert.equal(button.textContent, 'Отправить код повторно через 01:00');
        assert.equal(button.disabled, true);
        time += 61000;
        callbacks[event]();
        assert.equal(button.disabled, false);
        assert.equal(button.textContent, 'Отправить код повторно');
        assert.equal(fallback.hidden, true);
    });
}

test('persistent password countdown keeps password request labels after updates', () => {
    const passwordState = { ...state, persistent_password: true };
    assert.equal(resendPresentation(passwordState, 1000000).label, 'Запросить новый пароль через 01:00');
    assert.equal(resendPresentation(passwordState, 1060000).label, 'Запросить новый пароль по SMS');
    assert.equal(resendPresentation({ ...passwordState, send_count: 3 }, 1060000).disabled, true);
});
