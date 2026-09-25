@foreach($offers as $offer)
    @php
        $available = $offer->quantity === null || $offer->quantity >= 1;
        $inCart = (int) ($cartQuantities[$offer->id] ?? 0);
        $cartItemId = $cartItemIds[$offer->id] ?? null;
        $expired = $offer->expires_at?->lt(now()->startOfDay());
        $expiresSoon = $offer->expires_at?->lte(now()->addMonths(config('catalog.expiration_warning_months')));
        $hasSupplierDiscount = $offer->applied_supplier_discount_percent !== null && bccomp($offer->applied_supplier_discount_percent, '0', 2) === 1;
    @endphp
    <article class="mobile-offer-row" data-mobile-result-shell="list" data-mobile-offer-row data-offer-id="{{ $offer->id }}">
        <img src="{{ asset('images/catalog/product-placeholder.svg') }}" alt="" class="mobile-offer-image">
        <div class="min-w-0 grow">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h3 class="line-clamp-2 text-sm font-bold leading-tight text-ink">{{ $offer->medicine->name }}</h3>
                    <p class="mt-1 truncate text-xs text-muted">{{ collect([$offer->medicine->dosage, $offer->medicine->form])->filter()->join(' · ') ?: 'Форма не указана' }}</p>
                </div>
                <div class="shrink-0 text-right"><p class="whitespace-nowrap text-base font-extrabold text-brand-700">{{ $offer->effective_price }} <span class="text-xs">TJS</span></p>@if($hasSupplierDiscount)<p class="mt-0.5 whitespace-nowrap text-[10px] text-muted">{{ $offer->price }} TJS · Ваша скидка {{ $offer->applied_supplier_discount_percent }}%</p>@endif</div>
            </div>
            <div class="mt-2 grid grid-cols-[minmax(0,1fr)_auto] items-end gap-2">
                <div class="min-w-0 text-xs">
                    <p class="truncate font-medium">{{ $offer->organization->name }} <span class="font-normal text-muted">· {{ $offer->organization->city ?: 'Город не указан' }}</span></p>
                    <p class="mt-1 text-muted">Добавлен в систему: {{ $offer->created_at->format('d.m.Y') }}</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @if($expired)<span class="mobile-offer-badge bg-amber-50 text-warning">Срок годности истёк: {{ $offer->expires_at->format('d.m.Y') }}</span>
                        @elseif($offer->expires_at)<span class="mobile-offer-badge {{ $expiresSoon ? 'bg-amber-50 text-warning' : 'bg-slate-50 text-slate-600' }}">Срок: {{ $offer->expires_at->format('d.m.Y') }}</span>
                        @else<span class="mobile-offer-badge bg-slate-50 text-slate-600">Неуказан</span>
                        @endif
                        <span class="mobile-offer-badge {{ $available ? 'bg-brand-50 text-brand-700' : 'bg-amber-50 text-warning' }}">{{ $offer->quantity === null ? 'Остаток уточняется' : ($offer->quantity >= 1 ? 'В наличии: '.(int) $offer->quantity : 'Нет в наличии') }}</span>
                    </div>
                </div>
                <div data-cart-add-controls data-cart-can-add="{{ $canBuy && $available ? 'true' : 'false' }}" @class(['hidden' => $inCart > 0])><form method="post" action="{{ route('cart.add', $offer) }}" data-cart-form class="flex items-center gap-1.5">@csrf
                    <input type="number" name="quantity" value="1" min="1" max="999" @disabled($inCart > 0) class="min-h-10 w-14 rounded-control border border-slate-300 px-2 text-center text-sm" aria-label="Количество {{ $offer->medicine->name }}">
                    <x-ui.icon-button type="submit" label="Добавить в корзину" :disabled="$inCart > 0 || ! $canBuy || ! $available" class="!size-10 !min-h-0 !min-w-0 !rounded-lg !border-green-100 !bg-green-600 !text-white hover:!border-green-600 hover:!bg-green-700 disabled:!bg-green-600" data-cart-submit>
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 9V7a4 4 0 0 1 8 0v2M5 9h14l-1 10H6L5 9Zm4 4v3m6-3v3" /></svg>
                    </x-ui.icon-button>
                </form></div>
                <div data-cart-in-cart-controls @class(['hidden' => $inCart === 0])><div class="flex min-h-10 items-center gap-1.5 rounded-control bg-brand-50 px-2 text-brand-700" role="status" aria-label="Товар в корзине: {{ $inCart }} шт."><span class="relative grid size-7 shrink-0 place-items-center rounded-full bg-white text-brand-700 shadow-sm"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="size-3.5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.9a2 2 0 0 0 1.9-1.4L20.5 8H6.2M10 20.5h.01M17 20.5h.01" /></svg><span class="absolute -bottom-0.5 -right-0.5 grid size-3 place-items-center rounded-full bg-brand-600 text-white"><svg aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor" class="size-2"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m3.5 8 2.5 2.5 5-5" /></svg></span></span><span class="rounded-control border border-brand-100 bg-white px-1.5 py-1 text-xs font-bold tabular-nums"><span data-cart-quantity>{{ $inCart }}</span> <span aria-hidden="true">шт.</span><span class="sr-only"> единиц товара в корзине</span></span><form method="post" action="{{ $cartItemId ? route('cart.items.destroy', $cartItemId) : '' }}" data-cart-remove-form>@csrf @method('DELETE')<x-ui.icon-button type="submit" label="Удалить {{ $offer->medicine->name }} из корзины" class="!size-10 !min-h-0 !min-w-0 !rounded-lg !border-red-200 !text-danger hover:!bg-red-50"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 7h12m-9 0V5h6v2m-8 0 1 12h8l1-12" /></svg></x-ui.icon-button></form></div></div>
            </div>
        </div>
    </article>
@endforeach
