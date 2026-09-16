@foreach($offers as $offer)
    @php
        $updatedAt = $offer->import?->inventory_at ?? $offer->import?->activated_at ?? $offer->import?->updated_at;
        $stale = ! $updatedAt || $updatedAt->lt(now()->subHours(config('catalog.supplier_stale_hours')));
        $available = ($offer->quantity === null || $offer->quantity >= 1) && ! $stale;
        $inCart = (int) ($cartQuantities[$offer->id] ?? 0);
        $expiresSoon = $offer->expires_at?->lt(now()->addMonths(config('catalog.expiration_warning_months')));
    @endphp
    <article class="mobile-offer-row" data-mobile-result-shell="list" data-mobile-offer-row data-offer-id="{{ $offer->id }}">
        <img src="{{ asset('images/catalog/product-placeholder.svg') }}" alt="" class="mobile-offer-image">
        <div class="min-w-0 grow">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <h3 class="line-clamp-2 text-sm font-bold leading-tight text-ink">{{ $offer->medicine->name }}</h3>
                    <p class="mt-1 truncate text-xs text-muted">{{ collect([$offer->medicine->dosage, $offer->medicine->form])->filter()->join(' · ') ?: 'Форма не указана' }}</p>
                </div>
                <p class="shrink-0 whitespace-nowrap text-base font-extrabold text-brand-700">{{ $offer->price }} <span class="text-xs">TJS</span></p>
            </div>
            <div class="mt-2 grid grid-cols-[minmax(0,1fr)_auto] items-end gap-2">
                <div class="min-w-0 text-xs">
                    <p class="truncate font-medium">{{ $offer->organization->name }} <span class="font-normal text-muted">· {{ $offer->organization->city ?: 'Город не указан' }}</span></p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        <span class="mobile-offer-badge {{ $expiresSoon ? 'bg-amber-50 text-warning' : 'bg-slate-50 text-slate-600' }}">Срок: {{ $offer->expires_at?->format('d.m.Y') ?: 'не указан' }}</span>
                        <span class="mobile-offer-badge {{ $available ? 'bg-brand-50 text-brand-700' : 'bg-amber-50 text-warning' }}">{{ $offer->quantity === null ? 'Остаток уточняется' : ($offer->quantity >= 1 ? 'В наличии: '.(int) $offer->quantity : 'Нет в наличии') }}</span>
                    </div>
                </div>
                <form method="post" action="{{ route('cart.add', $offer) }}" data-cart-form class="flex items-center gap-1.5">@csrf
                    <input type="number" name="quantity" value="1" min="1" max="999" class="min-h-10 w-14 rounded-control border border-slate-300 px-2 text-center text-sm" aria-label="Количество {{ $offer->medicine->name }}">
                    <x-ui.icon-button label="{{ $inCart ? 'Добавить ещё' : 'Добавить в корзину' }}" :disabled="! $canBuy || ! $available" data-cart-submit>
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20.5 8H6.2M12 9v5m-2.5-2.5h5" /></svg>
                    </x-ui.icon-button>
                </form>
            </div>
        </div>
    </article>
@endforeach
