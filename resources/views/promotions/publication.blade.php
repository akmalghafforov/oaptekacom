<x-ui.card id="publication" class="scroll-mt-6 space-y-4">
    <h2 class="text-lg font-bold">Публикация</h2>
    <x-ui.status-badge :status="$status->value" :label="$status->label()" />
    @if($promotion->moderation_comment)
        <x-ui.alert type="warning"><p class="font-semibold">Комментарий администратора</p><p class="mt-1 whitespace-pre-line break-words">{{ $promotion->moderation_comment }}</p></x-ui.alert>
    @endif
    @if($promotion->exists)
        @if(in_array($status, [\App\Enums\PromotionStatus::NeedsRevision, \App\Enums\PromotionStatus::Rejected], true))
            <p class="text-sm text-muted">Сохраните исправления, чтобы вернуть акцию в черновик и отправить её на повторную проверку.</p>
        @endif
        <p class="text-sm text-muted">Каждый раздел сохраняется отдельно. Перед изменением статуса сохраните внесённые правки.</p>
        <p data-promotion-unsaved hidden role="status" class="text-sm font-medium text-warning">Есть несохранённые изменения. Сохраните их перед публикацией или отправкой на проверку.</p>
        <div class="space-y-3">
            @if($status === \App\Enums\PromotionStatus::Draft)
                @can('submit', $promotion)
                    <form method="post" action="{{ route('promotions.transition', $promotion) }}" data-promotion-publication>
                        @csrf <x-mode-version />
                        <x-ui.button name="action" value="submit" class="w-full">Отправить на проверку</x-ui.button>
                    </form>
                @endcan
            @endif
            @if($status === \App\Enums\PromotionStatus::PendingReview)
                @can('withdraw', $promotion)
                    <form method="post" action="{{ route('promotions.transition', $promotion) }}" data-promotion-publication>
                        @csrf <x-mode-version />
                        <x-ui.button name="action" value="withdraw" variant="secondary" class="w-full">Отозвать в черновик</x-ui.button>
                    </form>
                @endcan
            @endif
            @if(auth()->user()->isAdminMode() && in_array($status, [\App\Enums\PromotionStatus::Draft, \App\Enums\PromotionStatus::PendingReview], true))
                <form method="post" action="{{ route('promotions.transition', $promotion) }}" data-promotion-publication>
                    @csrf <x-mode-version />
                    <x-ui.button name="action" value="publish" class="w-full">Опубликовать</x-ui.button>
                </form>
            @endif
            @if($status === \App\Enums\PromotionStatus::PendingReview)
                @can('review', $promotion)
                    <x-ui.form-scope form-key="publication">
                        <form method="post" action="{{ route('promotions.transition', $promotion) }}" data-promotion-form data-promotion-publication class="space-y-3">
                            @csrf <x-mode-version />
                            <input type="hidden" name="_promotion_form" value="publication">
                            <x-ui.textarea name="comment" label="Причина возврата или отказа" required />
                            <x-ui.button name="action" value="revise" variant="secondary" class="w-full">Вернуть на исправление</x-ui.button>
                            <x-ui.button name="action" value="reject" variant="danger" class="w-full">Отклонить</x-ui.button>
                        </form>
                    </x-ui.form-scope>
                @endcan
            @endif
        </div>
    @else
        <p class="text-sm text-muted">Сохраните черновик, добавьте товары и материалы. После этого акцию можно отправить на проверку.</p>
    @endif
</x-ui.card>
