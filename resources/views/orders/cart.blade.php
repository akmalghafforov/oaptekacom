@extends('layouts.app')

@section('content')
<div class="cart-page">
<x-ui.page-header title="Заявки" :description="$cart->items->count().' позиций в работе'">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('supplier-requests.index')" variant="secondary">Архив</x-ui.button>
            <x-ui.button :href="route('catalog')">К поиску</x-ui.button>
        </div>
    </x-slot:actions>
</x-ui.page-header>

@if(($removedStaleItems ?? 0) > 0)
    <x-ui.alert type="warning" class="mb-4">Устаревшие позиции удалены, потому что поставщик обновил прайс-лист.</x-ui.alert>
@endif

@if(! config('orders.placement_enabled'))
    <x-ui.alert type="warning" class="mb-4">Оформление заказов временно недоступно. Вы можете собрать список товаров и поделиться им.</x-ui.alert>
@endif

@if($cart->items->isNotEmpty())
    @php
        $visibleGroups = $supplierGroups->filter(fn ($group) => ! $group['supplier']->price_list_hidden);
        $cartTotal = $visibleGroups->reduce(fn ($total, $group) => bcadd($total, $group['total'], 2), '0.00');
        $allShareText = "Заявки OAPTEKA\n".$visibleGroups->map(fn ($group) => $group['supplier']->name."\n".$group['items']->map(fn ($item) => "{$item->snapshot['medicine']} — {$item->quantity} × {$item->unit_price} TJS")->implode("\n")."\nИтого: {$group['total']} TJS")->implode("\n\n")."\nОбщий итог: {$cartTotal} TJS";
    @endphp
    @if($visibleGroups->count() !== $supplierGroups->count())
        <x-ui.alert type="warning" class="mb-4">Недоступные позиции остаются сохранены. К отправке доступны только видимые прайс-листы.</x-ui.alert>
    @endif
    <x-ui.card class="cart-page-summary mb-5">
        <div class="grid gap-4 sm:grid-cols-3">
            <div><p class="text-sm text-muted">Поставщики</p><p class="mt-1 text-2xl font-bold">{{ $supplierGroups->count() }}</p></div>
            <div><p class="text-sm text-muted">Позиций</p><p class="mt-1 text-2xl font-bold">{{ $cart->items->count() }}</p></div>
            <div><p class="text-sm text-muted">Итого</p><p class="mt-1 text-2xl font-bold text-accent" data-cart-total>{{ number_format((float) $cartTotal, 2, '.', ' ') }} TJS</p></div>
        </div>
    </x-ui.card>

    <x-ui.alert type="danger" class="mb-4" data-cart-action-error hidden>Не удалось сохранить количество. Проверьте позиции и повторите изменение.</x-ui.alert>
    <div class="cart-request-list space-y-3">
        @foreach($supplierGroups as $supplierId => $group)
            @php
                $supplier = $group['supplier'];
                $shareText = "Заявка OAPTEKA\nПоставщик: {$supplier->name}\n".$group['items']->map(fn ($item) => "{$item->snapshot['medicine']} — {$item->quantity} × {$item->unit_price} TJS")->implode("\n")."\nИтого: {$group['total']} TJS";
            @endphp
            <x-ui.card class="!p-0">
                <header class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <h2 class="min-w-0 break-words text-base font-bold">{{ $supplier->name }}</h2>
                    <div class="flex flex-wrap items-center gap-3"><span>{{ $group['items']->count() }} поз.</span><strong class="text-accent" data-cart-supplier-total="{{ $supplierId }}">{{ number_format((float) $group['total'], 2, '.', ' ') }} TJS</strong></div>
                </header>
                @if($supplier->price_list_hidden)<x-ui.alert type="warning">Прайс-лист временно скрыт</x-ui.alert>@endif
                <div class="cart-request-items overflow-y-auto">
                    <div class="table-wrap cart-request-table-wrap" tabindex="0" role="region" aria-label="Товары: {{ $supplier->name }}">
                        <table class="data-table cart-request-table">
                            <thead><tr><th>Товар</th><th>Цена</th><th>Количество</th><th>Сумма</th></tr></thead>
                            <tbody>
                                @foreach($group['items'] as $item)
                                    <tr>
                                        <td><strong class="block break-words text-sm">{{ $item->snapshot['medicine'] }}</strong><span class="cart-request-stock">@if($item->offer->quantity !== null)В наличии: {{ rtrim(rtrim(number_format((float) $item->offer->quantity, 2, '.', ''), '0'), '.') }} ед.@else Остаток уточняется у поставщика.@endif</span></td>
                                        <td><strong class="whitespace-nowrap">{{ $item->unit_price }} TJS</strong>@if(($item->snapshot['supplier_discount_percent'] ?? null) !== null && bccomp((string) $item->snapshot['supplier_discount_percent'], '0', 2) === 1)<span class="cart-request-discount">{{ $item->snapshot['raw_price'] ?? $item->snapshot['price'] }} TJS · Скидка {{ $item->snapshot['supplier_discount_percent'] }}%</span>@endif</td>
                                        <td>@if($supplier->price_list_hidden)<span>{{ $item->quantity }} шт.</span>@else<form method="post" action="{{ route('cart.update', $item) }}" class="cart-request-quantity" data-cart-quantity-form data-cart-item-id="{{ $item->id }}" data-cart-supplier-id="{{ $supplierId }}">@csrf <x-mode-version /> @method('PATCH')<x-ui.quantity-stepper name="quantity" :id="'quantity-'.$item->id" :value="$item->quantity" :max="$item->offer->quantity ?? 999999" /><span id="quantity-status-{{ $item->id }}" class="cart-request-quantity-status" data-cart-quantity-status role="status"></span></form>@endif</td>
                                        <td class="whitespace-nowrap"><strong class="block text-accent" data-cart-item-line-total="{{ $item->id }}">{{ bcmul((string) $item->unit_price, (string) $item->quantity, 2) }} TJS</strong><x-ui.icon-button label="Удалить {{ $item->snapshot['medicine'] }}" data-dialog-open aria-controls="cart-item-delete-{{ $item->id }}">×</x-ui.icon-button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="border-t border-border p-4" @unless($supplier->price_list_hidden) data-supplier-file-export="{{ $supplierId }}" aria-busy="false" @endunless>
                    <div class="grid gap-3 md:grid-cols-[auto_1fr_auto] md:items-start">
                        @unless($supplier->price_list_hidden)
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.button type="button" data-supplier-share data-supplier-id="{{ $supplierId }}" data-share-url="{{ route('cart.suppliers.share', $supplier) }}" data-share-text="{{ $shareText }}" data-csrf-token="{{ csrf_token() }}">Поделиться</x-ui.button>
                                <x-ui.dropdown :id="'supplier-download-'.$supplierId" label="Скачать">
                                    <x-ui.button type="button" variant="ghost" class="w-full justify-start" role="menuitem" data-file-prepare="pdf" :data-file-url="route('cart.suppliers.pdf', $supplier)">PDF</x-ui.button>
                                    <x-ui.button type="button" variant="ghost" class="w-full justify-start" role="menuitem" data-file-prepare="excel" :data-file-url="route('cart.suppliers.excel', $supplier)">Excel</x-ui.button>
                                </x-ui.dropdown>
                            </div>
                            <details class="order-3 text-sm text-muted md:order-none">
                                <summary class="flex min-h-10 cursor-pointer items-center rounded-control focus-visible:outline-focus">Как отправить?</summary>
                                <p class="max-w-sm py-2">Скачайте файл или выберите WhatsApp в меню отправки. Если отправка файлов недоступна, скачайте файл и прикрепите его в чате WhatsApp.</p>
                            </details>
                        @endunless
                        <div class="flex flex-wrap items-center gap-2 md:col-start-3 md:justify-end">
                            @unless($supplier->price_list_hidden)
                                @if(config('orders.placement_enabled'))
                                    <form data-cart-save-before-submit method="post" action="{{ route('cart.suppliers.checkout', $supplier) }}">@csrf <x-mode-version /> <x-ui.button>Оформить заказ</x-ui.button></form>
                                @endif
                                <x-ui.button type="button" variant="secondary" data-dialog-open aria-controls="supplier-archive-{{ $supplierId }}">В архив</x-ui.button>
                            @endunless
                            <x-ui.button type="button" variant="danger" data-dialog-open aria-controls="supplier-clear-{{ $supplierId }}">Очистить</x-ui.button>
                        </div>
                    </div>
                    @unless($supplier->price_list_hidden)
                        <div class="mt-3 flex flex-wrap items-center gap-2" data-file-feedback hidden>
                            <span class="size-4 shrink-0 rounded-full border-2 border-accent-strong border-t-transparent motion-safe:animate-spin" data-file-spinner aria-hidden="true" hidden></span>
                            <p class="text-sm" data-file-status role="status" aria-live="polite" aria-atomic="true"></p>
                            <x-ui.button type="button" variant="secondary" data-file-share hidden>Поделиться файлом</x-ui.button>
                        </div>
                        <a data-file-download hidden></a>
                    @endunless
                </div>
            </x-ui.card>

            @unless($supplier->price_list_hidden)
            <x-ui.confirmation-dialog name="supplier-archive-{{ $supplierId }}" title="Отправить в архив?" description="Заявка сохранится в архиве, а позиции этого поставщика удалятся из корзины."><form data-cart-save-before-submit method="post" action="{{ route('cart.suppliers.archive', $supplier) }}" class="flex flex-wrap justify-end gap-2">@csrf <x-mode-version /> <x-ui.button type="button" variant="secondary" data-dialog-close>Отмена</x-ui.button><x-ui.button>В архив</x-ui.button></form></x-ui.confirmation-dialog>

            @endunless
            <x-ui.confirmation-dialog name="supplier-clear-{{ $supplierId }}" title="Очистить заявку?" description="Все позиции этого поставщика будут удалены из корзины.">
                <form method="post" action="{{ route('cart.suppliers.destroy', $supplier) }}" class="flex flex-wrap justify-end gap-2">@csrf <x-mode-version /> @method('DELETE')<x-ui.button type="button" variant="secondary" data-dialog-close>Отмена</x-ui.button><x-ui.button variant="danger">Очистить заявку</x-ui.button></form>
            </x-ui.confirmation-dialog>

            @foreach($group['items'] as $item)
                <x-ui.confirmation-dialog name="cart-item-delete-{{ $item->id }}" title="Удалить позицию?" description="{{ $item->snapshot['medicine'] }} будет удалён из заявки.">
                    <form method="post" action="{{ route('cart.items.destroy', $item) }}" class="flex flex-wrap justify-end gap-2">@csrf <x-mode-version /> @method('DELETE')<x-ui.button type="button" variant="secondary" data-dialog-close>Отмена</x-ui.button><x-ui.button variant="danger">Удалить</x-ui.button></form>
                </x-ui.confirmation-dialog>
            @endforeach
        @endforeach
    </div>
    <x-ui.card class="cart-action-bar"><div><span class="text-sm text-muted">Итого к отправке</span><p class="text-xl font-bold text-accent" data-cart-total>{{ number_format((float) $cartTotal, 2, '.', ' ') }} TJS</p></div><div class="flex flex-wrap gap-2">@if(config('orders.placement_enabled'))<form data-cart-save-before-submit method="post" action="{{ route('cart.checkout') }}">@csrf <x-mode-version /> <x-ui.button :disabled="$visibleGroups->isEmpty()">Оформить все</x-ui.button></form>@endif<x-ui.button type="button" variant="secondary" data-cart-share data-share-text="{{ $allShareText }}" :disabled="$visibleGroups->isEmpty()">Поделиться</x-ui.button><x-ui.button type="button" variant="ghost" class="text-danger" data-dialog-open aria-controls="cart-clear-all">Очистить</x-ui.button></div></x-ui.card>
    <x-ui.confirmation-dialog name="cart-clear-all" title="Очистить корзину?" description="Все заявки и позиции будут удалены из корзины."><form method="post" action="{{ route('cart.destroy') }}" class="flex flex-wrap justify-end gap-2">@csrf <x-mode-version /> @method('DELETE')<x-ui.button type="button" variant="secondary" data-dialog-close>Отмена</x-ui.button><x-ui.button variant="danger">Очистить всё</x-ui.button></form></x-ui.confirmation-dialog>
@else
    <x-ui.empty-state title="Корзина пуста" description="Добавьте товары из каталога, чтобы сформировать заявку поставщику.">
        <x-slot:action><x-ui.button :href="route('catalog')">Перейти в каталог</x-ui.button></x-slot:action>
    </x-ui.empty-state>
@endif
</div>
@endsection
