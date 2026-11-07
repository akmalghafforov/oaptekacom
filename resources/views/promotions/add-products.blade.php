<div class="mt-5 space-y-3 border-t border-border pt-5">
    <details class="rounded-control border border-border p-3 sm:p-4" @if(old('_promotion_form') === 'manual') open @endif>
        <summary class="min-h-10 cursor-pointer content-center font-semibold">Добавить вручную</summary>
        <x-ui.form-scope form-key="manual">
            <form method="post" action="{{ route('promotions.items.store', $promotion) }}" data-promotion-form class="mt-4 space-y-4">
                @csrf <x-mode-version />
                <input type="hidden" name="_promotion_form" value="manual">
                @include('promotions.item-fields', ['item' => null, 'fieldId' => 'new', 'copy' => false, 'formKey' => 'manual'])
                <x-ui.button>Добавить товар</x-ui.button>
            </form>
        </x-ui.form-scope>
    </details>
    <details id="copy-products" class="scroll-mt-6 rounded-control border border-border p-3 sm:p-4" @if(old('_promotion_form') === 'copy' || request()->hasAny(['offer_search', 'offers_page'])) open @endif>
        <summary class="min-h-10 cursor-pointer content-center font-semibold">Копировать из прайс-листа</summary>
        <p class="mb-4 mt-2 text-sm text-muted">Копируются название и характеристики. Цену, скидку и наличие для акции укажите ниже.</p>
        <form method="get" action="{{ route('promotions.edit', $promotion) }}#copy-products" class="mb-4 flex min-w-0 flex-wrap items-end gap-2">
            <div class="min-w-0 flex-1"><x-ui.input name="offer_search" label="Поиск в прайс-листе" :value="$offerSearch" maxlength="255" placeholder="Название товара" /></div>
            <x-ui.button variant="secondary">Найти</x-ui.button>
            @if($offerSearch !== '')<x-ui.button :href="route('promotions.edit', $promotion).'#copy-products'" variant="ghost">Сбросить</x-ui.button>@endif
        </form>
        <p class="mb-3 text-sm text-muted">Найдено товаров: {{ $offers->total() }}</p>
        @if($offers->isNotEmpty() || $selectedOffer || old('_promotion_form') === 'copy')
            <x-ui.form-scope form-key="copy">
                <form method="post" action="{{ route('promotions.items.store', $promotion) }}" data-promotion-form class="space-y-4">
                    @csrf <x-mode-version />
                    <input type="hidden" name="_promotion_form" value="copy">
                    <x-ui.select name="source_offer_id" id="source-offer-copy" label="Товар из прайс-листа" required class="min-w-0 max-w-full">
                        <option value="">Выберите товар</option>
                        @if($selectedOffer && ! $offers->contains('id', $selectedOffer->id))
                            <option value="{{ $selectedOffer->id }}" selected>{{ $selectedOffer->source_name ?: $selectedOffer->medicine->name }} · {{ $selectedOffer->price }} TJS</option>
                        @endif
                        @foreach($offers as $offer)<option value="{{ $offer->id }}" @selected(old('_promotion_form') === 'copy' && old('source_offer_id') == $offer->id)>{{ $offer->source_name ?: $offer->medicine->name }} · {{ $offer->price }} TJS</option>@endforeach
                    </x-ui.select>
                    @include('promotions.item-fields', ['item' => null, 'fieldId' => 'copy', 'copy' => true, 'formKey' => 'copy'])
                    <x-ui.button>Копировать товар</x-ui.button>
                </form>
            </x-ui.form-scope>
        @else
            <x-ui.empty-state :title="$offerSearch !== '' ? 'Товары не найдены' : 'В текущем прайс-листе нет товаров'" :description="$offerSearch !== '' ? 'Попробуйте другое название или сбросьте поиск.' : 'Загрузите прайс-лист компании или добавьте товар вручную.'" />
        @endif
        <div class="mt-4">{{ $offers->links() }}</div>
    </details>
</div>
