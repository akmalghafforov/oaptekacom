<x-ui.card id="participation" class="min-w-0 scroll-mt-6">
    <h2 class="text-xl font-bold">Условия участия и призы</h2>
    <p class="mb-5 mt-1 text-sm text-muted">Аптека участвует автоматически после подтверждения заказов поставщиком. Победителей определяет организатор.</p>
    @if(! $promotion->exists)
        <p class="text-sm text-muted">Сначала сохраните черновик акции.</p>
    @else
        @php
            $participationLocked = $promotion->first_published_at !== null;
            $restoreParticipation = old('_promotion_form') === 'participation';
            $prizeValues = $restoreParticipation ? old('prizes', []) : $promotion->prizes->toArray();
            $prizeValues = is_array($prizeValues) ? array_values($prizeValues) : [];
            $prizeRows = $participationLocked ? count($prizeValues) : min(100, max(1, count($prizeValues), $restoreParticipation ? (int) old('_prize_rows', 0) : 0));
        @endphp
        @if($participationLocked)<x-ui.alert type="warning" class="mb-4">Условия участия и призы зафиксированы первой публикацией. Изменить их нельзя.</x-ui.alert>@endif
        <x-ui.form-scope form-key="participation">
            <form method="post" action="{{ route('promotions.participation-settings', $promotion) }}" @if($canEdit && ! $participationLocked) data-promotion-form @endif class="space-y-4">
                @csrf <x-mode-version /> @method('PATCH')
                <input type="hidden" name="_promotion_form" value="participation">
                <input type="hidden" name="prize_rows" value="{{ $prizeRows }}">
                <fieldset @disabled(! $canEdit || $participationLocked) class="min-w-0 space-y-4">
                    <x-ui.select name="participation_enabled" label="Призовое участие">
                        <option value="0" @selected(($restoreParticipation ? old('participation_enabled') : ($promotion->purchase_scope !== null ? '1' : '0')) == '0')>Без призового участия</option>
                        <option value="1" @selected(($restoreParticipation ? old('participation_enabled') : ($promotion->purchase_scope !== null ? '1' : '0')) == '1')>Включено</option>
                    </x-ui.select>
                    <x-ui.select name="purchase_scope" label="Какие покупки учитывать">
                        <option value="company" @selected(($restoreParticipation ? old('purchase_scope') : $promotion->purchase_scope?->value) !== 'promotion')>Все товары нашей компании</option>
                        <option value="promotion" @selected(($restoreParticipation ? old('purchase_scope') : $promotion->purchase_scope?->value) === 'promotion')>Только позиции этой акции</option>
                    </x-ui.select>
                    <x-ui.select name="condition_match" label="Выполнение условий">
                        <option value="all" @selected(($restoreParticipation ? old('condition_match') : $promotion->condition_match?->value) !== 'any')>Все указанные условия</option>
                        <option value="any" @selected(($restoreParticipation ? old('condition_match') : $promotion->condition_match?->value) === 'any')>Любое указанное условие</option>
                    </x-ui.select>
                    <p class="text-sm text-muted">Укажите хотя бы одно условие. Пустые условия не применяются. Учитываются цены после скидок.</p>
                    <div class="grid min-w-0 gap-3 sm:grid-cols-2">
                        <x-ui.input type="number" name="minimum_total" label="Сумма всех покупок не менее, TJS" :value="$promotion->minimum_total" min="0.01" max="99999999999999.99" step="0.01" />
                        <x-ui.input type="number" name="minimum_order_value" label="Один заказ на сумму не менее, TJS" :value="$promotion->minimum_order_value" min="0.01" max="99999999999999.99" step="0.01" />
                        <x-ui.input type="number" name="minimum_order_count" label="Количество заказов не менее" :value="$promotion->minimum_order_count" min="1" max="1000000" step="1" />
                    </div>
                    <h3 class="font-semibold">Призы</h3>
                    <p class="text-sm text-muted">Нужен хотя бы один приз. Чтобы удалить приз до публикации, очистите его поля и оставьте количество 1.</p>
                    @for($index = 0; $index < $prizeRows; $index++)
                        <fieldset class="min-w-0 space-y-3 border-t border-border pt-4">
                            <legend class="text-sm font-semibold">Приз {{ $index + 1 }}</legend>
                            <x-ui.input :name="'prizes['.$index.'][name]'" :id="'prize-'.$index.'-name'" :error-key="'prizes.'.$index.'.name'" label="Название приза" :value="$prizeValues[$index]['name'] ?? ''" maxlength="255" />
                            <x-ui.input :name="'prizes['.$index.'][description]'" :id="'prize-'.$index.'-description'" :error-key="'prizes.'.$index.'.description'" label="Описание (необязательно)" :value="$prizeValues[$index]['description'] ?? ''" maxlength="5000" />
                            <x-ui.input type="number" :name="'prizes['.$index.'][quantity]'" :id="'prize-'.$index.'-quantity'" :error-key="'prizes.'.$index.'.quantity'" label="Количество призов" :value="$prizeValues[$index]['quantity'] ?? 1" min="1" max="1000000" step="1" />
                        </fieldset>
                    @endfor
                    @if($restoreParticipation && $errors->has('prizes'))<x-ui.alert type="danger">{{ $errors->first('prizes') }}</x-ui.alert>@endif
                    @if($canEdit && ! $participationLocked)
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button>Сохранить условия и призы</x-ui.button>
                            @if($prizeRows < 100)<x-ui.button name="add_prize" value="1" variant="secondary" formnovalidate>Добавить ещё приз</x-ui.button>@endif
                        </div>
                    @endif
                </fieldset>
            </form>
        </x-ui.form-scope>
    @endif
</x-ui.card>
