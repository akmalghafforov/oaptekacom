@foreach($offers as $offer)
    @php
        $available = $offer->quantity === null || $offer->quantity >= 1;
        $expired = $offer->expires_at?->lt(now()->startOfDay());
        $expiresSoon = $offer->expires_at?->lte(now()->addMonths(config('catalog.expiration_warning_months')));
        $inCart = (int) ($cartQuantities[$offer->id] ?? 0);
        $cartItemId = $cartItemIds[$offer->id] ?? null;
        $hasSupplierDiscount = $offer->applied_supplier_discount_percent !== null && bccomp($offer->applied_supplier_discount_percent, '0', 2) === 1;
    @endphp
    <tr data-offer-id="{{ $offer->id }}">
        <td><img src="{{ asset('images/catalog/product-placeholder.svg') }}" alt="" class="size-16 rounded-control object-cover"></td>
        <td><p class="font-bold text-ink">{{ $offer->medicine->name }}</p><p class="mt-1 text-xs text-muted">{{ collect([$offer->medicine->dosage, $offer->medicine->form])->filter()->join(' · ') ?: 'Форма не указана' }}</p></td>
        <td>{{ $offer->medicine->manufacturer ?: '—' }}<span class="sr-only">{{ $offer->medicine->manufacturer ? '' : 'Производитель не указан' }}</span></td>
        <td>
            @if($expired)<p class="text-warning">Срок годности истёк: {{ $offer->expires_at->format('d.m.Y') }}</p>
            @elseif($offer->expires_at)<p>{{ $offer->expires_at->format('d.m.Y') }}</p>@if($expiresSoon)<p class="mt-1 text-xs text-warning">Срок годности менее 5 месяцев</p>@endif
            @else<p>Неуказан</p>
            @endif
        </td>
        <td><p class="font-medium">{{ $offer->organization->name }}</p><p class="text-xs text-muted">{{ $offer->organization->city ?: 'Город не указан' }}</p><p class="mt-1 text-xs text-brand-700">Добавлен в систему: {{ $offer->created_at->format('d.m.Y') }}</p></td>
        <td class="whitespace-nowrap"><strong class="text-lg text-brand-700">{{ $offer->effective_price }} TJS</strong>@if($hasSupplierDiscount)<span class="block text-xs text-muted">{{ $offer->price }} TJS · Ваша скидка {{ $offer->applied_supplier_discount_percent }}%</span>@endif @if($offer->old_price > $offer->price)<del class="block text-xs text-muted">{{ $offer->old_price }} TJS</del>@endif</td>
        <td>
            <div data-cart-add-controls data-cart-can-add="{{ $canBuy && $available ? 'true' : 'false' }}" @class(['hidden' => $inCart > 0])><form method="post" action="{{ route('cart.add', $offer) }}" data-cart-form>@csrf <div class="flex min-w-44 gap-2"><input type="number" name="quantity" value="1" min="1" max="999" @disabled($inCart > 0) class="min-h-10 w-16 rounded-control border border-slate-300 px-2" aria-label="Количество {{ $offer->medicine->name }}"><x-ui.button class="whitespace-nowrap" :disabled="$inCart > 0 || ! $canBuy || ! $available">В корзину</x-ui.button></div></form></div>
            <div data-cart-in-cart-controls @class(['hidden' => $inCart === 0])><div class="flex min-h-10 min-w-44 items-center justify-between gap-2 rounded-control bg-brand-50 px-2 text-brand-700" role="status" aria-label="Товар в корзине: {{ $inCart }} шт."><div class="flex items-center gap-1.5"><span class="relative grid size-7 shrink-0 place-items-center rounded-full bg-white text-brand-700 shadow-sm"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="size-3.5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L20.5 8H6.2M10 20.5h.01M17 20.5h.01" /></svg><span class="absolute -bottom-0.5 -right-0.5 grid size-3 place-items-center rounded-full bg-brand-600 text-white"><svg aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor" class="size-2"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m3.5 8 2.5 2.5 5-5" /></svg></span></span><span class="rounded-control border border-brand-100 bg-white px-1.5 py-1 text-xs font-bold tabular-nums"><span data-cart-quantity>{{ $inCart }}</span> <span aria-hidden="true">шт.</span><span class="sr-only"> единиц товара в корзине</span></span></div><form method="post" action="{{ $cartItemId ? route('cart.items.destroy', $cartItemId) : '' }}" data-cart-remove-form>@csrf @method('DELETE')<x-ui.icon-button type="submit" label="Удалить {{ $offer->medicine->name }} из корзины" class="!size-10 !min-h-0 !min-w-0 !rounded-lg !border-red-200 !text-danger hover:!bg-red-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 7h12m-9 0V5h6v2m-8 0 1 12h8l1-12" /></svg></x-ui.icon-button></form></div></div>
        </td>
    </tr>
@endforeach
