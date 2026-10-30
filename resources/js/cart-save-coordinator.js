export function createCartSaveCoordinator(groups, isBlocked = () => false) {
    let queue = Promise.resolve();
    return {
        enqueue(save) {
            queue = queue.then(() => {
                if (isBlocked()) throw new Error('Сессия устарела. Обновите страницу.');
                return save();
            });
            // Keep rejected queues observable by flush without unhandled rejections.
            queue.catch(() => {});
        },
        async flush() {
            do {
                if (isBlocked()) throw new Error('Сессия устарела. Обновите страницу.');
                groups.forEach((group) => group.flush());
                await queue;
            } while ([...groups.values()].some((group) => group.saving || group.desired !== group.confirmed));
            if (isBlocked() || [...groups.values()].some((group) => group.error)) {
                throw new Error('Не удалось сохранить количество.');
            }
        },
    };
}

export function initializeCartSaveBeforeSubmit(form, { flush, isBlocked, feedback, createElement }) {
    let submitting = false;
    form.addEventListener('submit', async (event) => {
        if (submitting && !isBlocked()) return;
        event.preventDefault();
        try {
            await flush();
            feedback.hidden = true;
            submitting = true;
            form.requestSubmit(event.submitter);
            submitting = false;
        } catch {
            feedback.hidden = false;
            let localFeedback = form.querySelector('[data-cart-submit-error]');
            if (!localFeedback) {
                localFeedback = createElement();
                localFeedback.dataset.cartSubmitError = '';
                localFeedback.className = 'w-full text-sm text-danger';
                localFeedback.setAttribute('role', 'alert');
                form.append(localFeedback);
            }
            localFeedback.textContent = feedback.textContent;
        }
    });
}
