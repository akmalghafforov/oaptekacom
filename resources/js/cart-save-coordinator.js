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
