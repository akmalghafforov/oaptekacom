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
        $cartTotal = $supplierGroups->reduce(fn ($total, $group) => bcadd($total, $group['total'], 2), '0.00');
        $allShareText = "Заявки OAPTEKA\n".$supplierGroups->map(fn ($group) => $group['supplier']->name."\n".$group['items']->map(fn ($item) => "{$item->snapshot['medicine']} — {$item->quantity} × {$item->unit_price} TJS")->implode("\n")."\nИтого: {$group['total']} TJS")->implode("\n\n")."\nОбщий итог: {$cartTotal} TJS";
    @endphp
    <x-ui.card class="cart-page-summary mb-5">
        <div class="grid gap-4 sm:grid-cols-3">
            <div><p class="text-sm text-muted">Поставщики</p><p class="mt-1 text-2xl font-bold">{{ $supplierGroups->count() }}</p></div>
            <div><p class="text-sm text-muted">Позиций</p><p class="mt-1 text-2xl font-bold">{{ $cart->items->count() }}</p></div>
            <div><p class="text-sm text-muted">Итого</p><p class="mt-1 text-2xl font-bold text-brand-700" data-cart-total>{{ number_format((float) $cartTotal, 2, '.', ' ') }} TJS</p></div>
        </div>
    </x-ui.card>

    <div class="cart-request-list space-y-3">
        @foreach($supplierGroups as $supplierId => $group)
            @php
                $supplier = $group['supplier'];
                $shareText = "Заявка OAPTEKA\nПоставщик: {$supplier->name}\n".$group['items']->map(fn ($item) => "{$item->snapshot['medicine']} — {$item->quantity} × {$item->unit_price} TJS")->implode("\n")."\nИтого: {$group['total']} TJS";
            @endphp
            <x-ui.card class="!p-0 overflow-hidden">
                <button type="button" class="cart-request-row" data-dialog-open aria-controls="supplier-review-{{ $supplierId }}"><span class="cart-request-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($supplier->name, 0, 2)) }}</span><span class="min-w-0 flex-1 text-left"><span class="block text-xs text-muted">Заявка №{{ $loop->iteration }}</span><span class="block truncate text-base font-bold">{{ $supplier->name }}</span></span><span class="cart-request-meta"><span class="rounded-lg bg-brand-50 px-2 py-1 text-xs font-semibold text-brand-700">{{ $group['items']->count() }} поз.</span><strong data-cart-supplier-total="{{ $supplierId }}">{{ number_format((float) $group['total'], 2, '.', ' ') }} TJS</strong><span class="text-sm text-muted">Открыть ›</span></span></button>
            </x-ui.card>

            <x-ui.dialog name="supplier-review-{{ $supplierId }}" class="cart-request-dialog">
                <x-slot:header>
                    <div class="cart-request-heading">
                        <span class="cart-request-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($supplier->name, 0, 2)) }}</span>
                        <div class="min-w-0">
                            <p id="supplier-review-{{ $supplierId }}-description" class="text-xs font-semibold text-muted">Заявка поставщику №{{ $loop->iteration }}</p>
                            <h2 id="supplier-review-{{ $supplierId }}-title" class="break-words text-base font-bold">{{ $supplier->name }}</h2>
                        </div>
                    </div>
                    <div class="cart-request-heading-meta">
                        <span class="rounded-lg border border-brand-100 bg-brand-50 px-2 py-1 text-xs font-semibold text-brand-700">{{ $group['items']->count() }} поз.</span>
                        <strong class="whitespace-nowrap text-base text-brand-700" data-cart-supplier-total="{{ $supplierId }}">{{ number_format((float) $group['total'], 2, '.', ' ') }} TJS</strong>
                        <x-ui.icon-button label="Закрыть" data-dialog-close>×</x-ui.icon-button>
                    </div>
                </x-slot:header>
                <div class="cart-request-items overflow-y-auto">
                    <div class="table-wrap cart-request-table-wrap hidden md:block">
                        <table class="data-table cart-request-table">
                            <thead><tr><th>Товар</th><th>Цена</th><th>Количество</th><th>Сумма</th><th><span class="sr-only">Действие</span></th></tr></thead>
                            <tbody>
                                @foreach($group['items'] as $item)
                                    <tr>
                                        <td><strong class="block break-words text-sm">{{ $item->snapshot['medicine'] }}</strong><span class="cart-request-stock">@if($item->offer->quantity !== null)В наличии: {{ rtrim(rtrim(number_format((float) $item->offer->quantity, 2, '.', ''), '0'), '.') }} ед.@else Остаток уточняется у поставщика.@endif</span></td>
                                        <td><strong class="whitespace-nowrap">{{ $item->unit_price }} TJS</strong>@if(($item->snapshot['supplier_discount_percent'] ?? null) !== null && bccomp((string) $item->snapshot['supplier_discount_percent'], '0', 2) === 1)<span class="cart-request-discount">{{ $item->snapshot['raw_price'] ?? $item->snapshot['price'] }} TJS · Скидка {{ $item->snapshot['supplier_discount_percent'] }}%</span>@endif</td>
                                        <td><form method="post" action="{{ route('cart.update', $item) }}" class="cart-request-quantity" data-cart-quantity-form data-cart-item-id="{{ $item->id }}">@csrf @method('PATCH')<x-ui.quantity-stepper name="quantity" :id="'quantity-desktop-'.$item->id" :value="$item->quantity" :max="$item->offer->quantity ?? 999" /><span class="cart-request-quantity-status" data-cart-quantity-status role="status"></span></form></td>
                                        <td class="whitespace-nowrap font-semibold text-brand-700" data-cart-item-line-total="{{ $item->id }}">{{ bcmul((string) $item->unit_price, (string) $item->quantity, 2) }} TJS</td>
                                        <td><x-ui.icon-button label="Удалить {{ $item->snapshot['medicine'] }}" data-dialog-open aria-controls="cart-item-delete-{{ $item->id }}">×</x-ui.icon-button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="cart-request-mobile-items md:hidden">
                        @foreach($group['items'] as $item)
                            <article class="cart-request-mobile-item">
                                <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="break-words font-semibold">{{ $item->snapshot['medicine'] }}</h3><p class="text-sm text-muted">{{ $item->unit_price }} TJS за единицу</p>@if(($item->snapshot['supplier_discount_percent'] ?? null) !== null && bccomp((string) $item->snapshot['supplier_discount_percent'], '0', 2) === 1)<p class="cart-request-discount">{{ $item->snapshot['raw_price'] ?? $item->snapshot['price'] }} TJS · Скидка {{ $item->snapshot['supplier_discount_percent'] }}%</p>@endif</div><strong class="shrink-0 whitespace-nowrap text-sm text-brand-700" data-cart-item-line-total="{{ $item->id }}">{{ bcmul((string) $item->unit_price, (string) $item->quantity, 2) }} TJS</strong></div>
                                <p class="cart-request-stock">@if($item->offer->quantity !== null)В наличии: {{ rtrim(rtrim(number_format((float) $item->offer->quantity, 2, '.', ''), '0'), '.') }} ед.@else Остаток уточняется у поставщика.@endif</p>
                                <div class="flex flex-wrap items-end justify-between gap-2"><form method="post" action="{{ route('cart.update', $item) }}" class="cart-request-quantity" data-cart-quantity-form data-cart-item-id="{{ $item->id }}">@csrf @method('PATCH')<x-ui.quantity-stepper name="quantity" :id="'quantity-mobile-'.$item->id" :value="$item->quantity" :max="$item->offer->quantity ?? 999" /><span class="cart-request-quantity-status" data-cart-quantity-status role="status"></span></form><x-ui.icon-button label="Удалить {{ $item->snapshot['medicine'] }}" data-dialog-open aria-controls="cart-item-delete-{{ $item->id }}">×</x-ui.icon-button></div>
                            </article>
                        @endforeach
                    </div>
                </div>
                <div class="cart-dialog-footer">@if(config('orders.placement_enabled'))<form method="post" action="{{ route('cart.suppliers.checkout', $supplier) }}">@csrf <x-ui.button>Оформить заказ</x-ui.button></form>@endif<x-ui.button type="button" variant="secondary" data-dialog-open aria-controls="supplier-share-{{ $supplierId }}">Поделиться</x-ui.button><x-ui.button type="button" variant="secondary" data-dialog-open aria-controls="supplier-archive-{{ $supplierId }}">В архив</x-ui.button><x-ui.button type="button" variant="ghost" class="text-danger" data-dialog-open aria-controls="supplier-clear-{{ $supplierId }}">Очистить</x-ui.button></div>
            </x-ui.dialog>

            <x-ui.dialog name="supplier-share-{{ $supplierId }}" title="Предварительная заявка" description="Проверьте заявку перед отправкой поставщику."><div class="space-y-3 overflow-y-auto p-5"><div class="rounded-xl bg-brand-50 p-3"><span class="text-xs text-brand-700">Итого в заявке</span><p class="text-2xl font-bold text-brand-700" data-cart-supplier-total="{{ $supplierId }}">{{ number_format((float) $group['total'], 2, '.', ' ') }} TJS</p></div><h3 class="font-bold">{{ $supplier->name }}</h3>@foreach($group['items'] as $item)<div class="flex justify-between gap-3 border-b border-slate-100 pb-2 text-sm"><span>{{ $item->snapshot['medicine'] }} · <span data-cart-item-quantity="{{ $item->id }}">{{ $item->quantity }} шт.</span></span><strong data-cart-item-line-total="{{ $item->id }}">{{ bcmul((string) $item->unit_price, (string) $item->quantity, 2) }} TJS</strong></div>@endforeach</div><div class="cart-dialog-footer"><x-ui.button type="button" data-supplier-share data-supplier-id="{{ $supplierId }}" data-share-url="{{ route('cart.suppliers.share', $supplier) }}" data-share-text="{{ $shareText }}" data-csrf-token="{{ csrf_token() }}">Поделиться</x-ui.button></div></x-ui.dialog>

            <x-ui.confirmation-dialog name="supplier-archive-{{ $supplierId }}" title="Отправить в архив?" description="Заявка сохранится в архиве, а позиции этого поставщика удалятся из корзины."><form method="post" action="{{ route('cart.suppliers.archive', $supplier) }}" class="flex flex-wrap justify-end gap-2">@csrf <x-ui.button type="button" variant="secondary" data-dialog-close>Отмена</x-ui.button><x-ui.button>В архив</x-ui.button></form></x-ui.confirmation-dialog>

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
    <x-ui.card class="cart-action-bar"><div><span class="text-sm text-muted">Итого к отправке</span><p class="text-xl font-bold text-brand-700" data-cart-total>{{ number_format((float) $cartTotal, 2, '.', ' ') }} TJS</p></div><div class="flex flex-wrap gap-2">@if(config('orders.placement_enabled'))<form method="post" action="{{ route('cart.checkout') }}">@csrf <x-ui.button>Оформить все</x-ui.button></form>@endif<x-ui.button type="button" variant="secondary" data-dialog-open aria-controls="cart-share-all">Поделиться</x-ui.button><x-ui.button type="button" variant="ghost" class="text-danger" data-dialog-open aria-controls="cart-clear-all">Очистить</x-ui.button></div></x-ui.card>
    <x-ui.dialog name="cart-share-all" title="Заявки поставщикам" description="Проверьте заявки перед отправкой."><div class="space-y-4 overflow-y-auto p-5"><div class="rounded-xl bg-brand-50 p-3"><span class="text-xs text-brand-700">Итого в заявках</span><p class="text-2xl font-bold text-brand-700" data-cart-total>{{ number_format((float) $cartTotal, 2, '.', ' ') }} TJS</p></div>@foreach($supplierGroups as $group)<section class="space-y-2 rounded-xl border border-slate-200 p-3"><div class="flex justify-between gap-2 font-bold"><h3>{{ $group['supplier']->name }}</h3><span data-cart-supplier-total="{{ $group['supplier']->id }}">{{ $group['total'] }} TJS</span></div>@foreach($group['items'] as $item)<div class="flex justify-between gap-2 text-sm"><span>{{ $item->snapshot['medicine'] }} · <span data-cart-item-quantity="{{ $item->id }}">{{ $item->quantity }} шт.</span></span><span data-cart-item-line-total="{{ $item->id }}">{{ bcmul((string) $item->unit_price, (string) $item->quantity, 2) }} TJS</span></div>@endforeach</section>@endforeach</div><div class="cart-dialog-footer"><x-ui.button type="button" data-cart-share data-share-text="{{ $allShareText }}">Поделиться</x-ui.button></div></x-ui.dialog>
    <x-ui.confirmation-dialog name="cart-clear-all" title="Очистить корзину?" description="Все заявки и позиции будут удалены из корзины."><form method="post" action="{{ route('cart.destroy') }}" class="flex flex-wrap justify-end gap-2">@csrf @method('DELETE')<x-ui.button type="button" variant="secondary" data-dialog-close>Отмена</x-ui.button><x-ui.button variant="danger">Очистить всё</x-ui.button></form></x-ui.confirmation-dialog>
@else
    <x-ui.empty-state title="Корзина пуста" description="Добавьте товары из каталога, чтобы сформировать заявку поставщику.">
        <x-slot:action><x-ui.button :href="route('catalog')">Перейти в каталог</x-ui.button></x-slot:action>
    </x-ui.empty-state>
@endif
</div>
@endsection
