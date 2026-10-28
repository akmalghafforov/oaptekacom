export function resendPresentation(state, time) {
    const exhausted = state.send_count >= 3 && state.allowance_resets_at > time;
    const seconds = state.allowance_resets_at !== null && state.allowance_resets_at <= time
        ? 0 : Math.max(0, Math.ceil(((state.resend_available_at ?? time) - time) / 1000));
    const remaining = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
    return {
        exhausted,
        disabled: exhausted || seconds > 0,
        label: !exhausted && seconds > 0 ? `Отправить код повторно через ${remaining}` : 'Отправить код повторно',
    };
}

export function initializeOtpResend(root, document, window) {
    const state = JSON.parse(root.dataset.otpResend);
    const startedAt = Date.now();
    const button = root.querySelector('[data-otp-resend-button]');
    const fallback = root.querySelector('[data-otp-fallback]');
    const update = () => {
        const presentation = resendPresentation(state, state.server_time + Math.max(0, Date.now() - startedAt));
        button.disabled = presentation.disabled;
        button.textContent = presentation.label;
        fallback.hidden = !presentation.exhausted;
    };
    update();
    window.setInterval(update, 250);
    document.addEventListener('visibilitychange', update);
    window.addEventListener('pageshow', update);
}
