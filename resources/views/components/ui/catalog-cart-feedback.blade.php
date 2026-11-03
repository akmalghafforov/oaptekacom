@props(['offer', 'canBuy', 'id'])
@php
    $explanation = ! $canBuy
        ? 'Добавление в корзину недоступно в текущем режиме'
        : ($offer->quantity !== null && $offer->quantity < 1
            ? ($offer->quantity <= 0 ? 'Нет в наличии у поставщика' : 'Недостаточно остатка для заказа')
            : null);
@endphp
<div id="{{ $id }}" {{ $attributes }}>
    @if($explanation)
        <x-ui.alert type="warning" class="mt-2 !px-2 !py-1.5" data-cart-availability>{{ $explanation }}</x-ui.alert>
    @endif
    <x-ui.alert type="danger" class="mt-2 !px-2 !py-1.5" data-cart-error hidden />
</div>
