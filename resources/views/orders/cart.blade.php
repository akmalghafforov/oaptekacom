@extends('layouts.app')

@section('content')
<x-ui.page-header title="Корзина" description="Заявки и заказы оформляются отдельно для каждого поставщика.">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('catalog')" variant="secondary">Вернуться к поиску</x-ui.button>
            <x-ui.button :href="route('supplier-requests.index')" variant="ghost">Архив заявок</x-ui.button>
        </div>
    </x-slot:actions>
</x-ui.page-header>

@if(($removedStaleItems ?? 0) > 0)
    <x-ui.alert type="warning" class="mb-4">Устаревшие позиции удалены, потому что поставщик обновил прайс-лист.</x-ui.alert>
@endif

@if($cart->items->isNotEmpty())
    <x-ui.card class="mb-5">
        <div class="grid gap-4 sm:grid-cols-3">
            <div><p class="text-sm text-muted">Поставщики</p><p class="mt-1 text-2xl font-bold">{{ $supplierGroups->count() }}</p></div>
            <div><p class="text-sm text-muted">Товары</p><p class="mt-1 text-2xl font-bold">{{ $cart->items->sum('quantity') }}</p></div>
            <div><p class="text-sm text-muted">Общая сумма</p><p class="mt-1 text-2xl font-bold text-brand-700">{{ number_format((float) $supplierGroups->sum('total'), 2, '.', ' ') }} TJS</p></div>
        </div>
    </x-ui.card>

    <div class="space-y-4 pb-20 sm:pb-0">
        @foreach($supplierGroups as $supplierId => $group)
            @php
                $supplier = $group['supplier'];
                $shareText = "Заявка OAPTEKA\nПоставщик: {$supplier->name}\n".$group['items']->map(fn ($item) => "{$item->snapshot['medicine']} — {$item->quantity} × {$item->unit_price} TJS")->implode("\n")."\nИтого: {$group['total']} TJS";
            @endphp
            <x-ui.card class="space-y-4">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-lg font-bold">{{ $supplier->name }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ $group['items']->count() }} поз. · {{ $group['items']->sum('quantity') }} ед. · {{ number_format((float) $group['total'], 2, '.', ' ') }} TJS</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="button" variant="secondary" data-dialog-open aria-controls="supplier-review-{{ $supplierId }}">Открыть заявку</x-ui.button>
                        <form method="post" action="{{ route('cart.suppliers.checkout', $supplier) }}">@csrf <x-ui.button>Оформить заказ</x-ui.button></form>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                    <x-ui.button type="button" variant="ghost" data-dialog-open aria-controls="supplier-share-{{ $supplierId }}">Поделиться</x-ui.button>
                    <x-ui.button type="button" variant="ghost" data-dialog-open aria-controls="supplier-clear-{{ $supplierId }}">Очистить заявку</x-ui.button>
                </div>
            </x-ui.card>

            <x-ui.dialog name="supplier-review-{{ $supplierId }}" title="Заявка: {{ $supplier->name }}" description="Проверьте количество и актуальность позиций перед отправкой.">
                <div class="space-y-4 overflow-y-auto p-5">
                    @foreach($group['items'] as $item)
                        @php($lineTotal = bcmul((string) $item->unit_price, (string) $item->quantity, 2))
                        <article class="space-y-3 border-b border-slate-100 pb-4 last:border-0 last:pb-0">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div><h3 class="font-semibold">{{ $item->snapshot['medicine'] }}</h3><p class="mt-1 text-sm text-muted">{{ $item->unit_price }} TJS за единицу</p>@if(($item->snapshot['supplier_discount_percent'] ?? null) !== null && bccomp((string) $item->snapshot['supplier_discount_percent'], '0', 2) === 1)<p class="mt-1 text-xs text-muted">{{ $item->snapshot['raw_price'] ?? $item->snapshot['price'] }} TJS · Ваша скидка {{ $item->snapshot['supplier_discount_percent'] }}%</p>@endif</div>
                                <p class="font-semibold text-brand-700">{{ $lineTotal }} TJS</p>
                            </div>
                            <p class="text-xs text-muted">@if($item->offer->quantity !== null)В наличии: {{ rtrim(rtrim(number_format((float) $item->offer->quantity, 2, '.', ''), '0'), '.') }} ед.@else Остаток уточняется у поставщика.@endif</p>
                            <div class="flex flex-wrap items-end justify-between gap-3">
                                <form method="post" action="{{ route('cart.update', $item) }}" class="flex flex-wrap items-end gap-2">@csrf @method('PATCH')<x-ui.quantity-stepper name="quantity" :id="'quantity-'.$item->id" :value="$item->quantity" :max="$item->offer->quantity ?? 999" /><x-ui.button variant="secondary">Обновить</x-ui.button></form>
                                <x-ui.button type="button" variant="ghost" class="text-danger hover:bg-red-50" data-dialog-open aria-controls="cart-item-delete-{{ $item->id }}">Удалить</x-ui.button>
                            </div>
                        </article>
                    @endforeach
                    <div class="flex items-center justify-between border-t border-slate-200 pt-4 text-lg font-bold"><span>Итого</span><span>{{ number_format((float) $group['total'], 2, '.', ' ') }} TJS</span></div>
                </div>
            </x-ui.dialog>

            <x-ui.confirmation-dialog name="supplier-share-{{ $supplierId }}" title="Поделиться заявкой" description="После успешной отправки или копирования заявка будет сохранена в архиве, а её позиции удалятся из корзины.">
                <div class="flex flex-wrap justify-end gap-2"><x-ui.button type="button" variant="secondary" data-dialog-close>Отмена</x-ui.button><x-ui.button type="button" data-supplier-share data-share-url="{{ route('cart.suppliers.share', $supplier) }}" data-share-text="{{ $shareText }}" data-csrf-token="{{ csrf_token() }}">Поделиться и очистить</x-ui.button></div>
            </x-ui.confirmation-dialog>

            <x-ui.confirmation-dialog name="supplier-clear-{{ $supplierId }}" title="Очистить заявку?" description="Все позиции этого поставщика будут удалены из корзины.">
                <form method="post" action="{{ route('cart.suppliers.destroy', $supplier) }}" class="flex flex-wrap justify-end gap-2">@csrf @method('DELETE')<x-ui.button type="button" variant="secondary" data-dialog-close>Отмена</x-ui.button><x-ui.button variant="danger">Очистить заявку</x-ui.button></form>
            </x-ui.confirmation-dialog>

            @foreach($group['items'] as $item)
                <x-ui.confirmation-dialog name="cart-item-delete-{{ $item->id }}" title="Удалить позицию?" description="{{ $item->snapshot['medicine'] }} будет удалён из заявки.">
                    <form method="post" action="{{ route('cart.items.destroy', $item) }}" class="flex flex-wrap justify-end gap-2">@csrf @method('DELETE')<x-ui.button type="button" variant="secondary" data-dialog-close>Отмена</x-ui.button><x-ui.button variant="danger">Удалить</x-ui.button></form>
                </x-ui.confirmation-dialog>
            @endforeach
        @endforeach
    </div>
@else
    <x-ui.empty-state title="Корзина пуста" description="Добавьте товары из каталога, чтобы сформировать заявку поставщику.">
        <x-slot:action><x-ui.button :href="route('catalog')">Перейти в каталог</x-ui.button></x-slot:action>
    </x-ui.empty-state>
@endif
@endsection
