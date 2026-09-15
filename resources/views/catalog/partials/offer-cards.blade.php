@foreach($offers as $offer)
    @php
        $updatedAt = $offer->import?->inventory_at ?? $offer->import?->activated_at ?? $offer->import?->updated_at;
        $stale = ! $updatedAt || $updatedAt->lt(now()->subHours(config('catalog.supplier_stale_hours')));
        $available = ($offer->quantity === null || $offer->quantity >= 1) && ! $stale;
        $hasDiscount = $offer->old_price && $offer->old_price > $offer->price;
        $discount = $hasDiscount ? (int) round((1 - ((float) $offer->price / (float) $offer->old_price)) * 100) : null;
        $inCart = (int) ($cartQuantities[$offer->id] ?? 0);
    @endphp
    <x-ui.card class="catalog-offer-card flex flex-col gap-4" data-offer-id="{{ $offer->id }}">
        <div class="relative overflow-hidden rounded-panel bg-slate-50"><img src="{{ asset('images/catalog/product-placeholder.svg') }}" alt="" class="aspect-[4/3] w-full object-cover">@if($discount)<span class="absolute left-2 top-2 rounded-full bg-danger px-2 py-1 text-xs font-bold text-white">−{{ $discount }}%</span>@endif</div>
        <div><p class="text-lg font-bold text-ink">{{ $offer->medicine->name }}</p><p class="mt-1 text-sm text-slate-600">{{ collect([$offer->medicine->dosage, $offer->medicine->form])->filter()->join(' · ') ?: 'Форма не указана' }}</p><p class="mt-2 text-sm text-muted">{{ $offer->medicine->manufacturer ?: 'Производитель не указан' }}</p></div>
        <div class="rounded-control bg-slate-50 p-3"><p class="font-medium">{{ $offer->organization->name }}</p><p class="text-xs text-muted">{{ $offer->organization->city ?: 'Город не указан' }}</p><p class="mt-1 text-xs {{ $stale ? 'text-warning' : 'text-brand-700' }}">{{ $stale ? 'Данные старше 48 часов' : 'Обновлено '.$updatedAt?->diffForHumans() }}</p></div>
        @if($offer->expires_at?->lt(now()->addMonths(config('catalog.expiration_warning_months'))))<p class="rounded-control bg-amber-50 px-3 py-2 text-sm text-warning">Срок годности менее 5 месяцев</p>@endif
        <div class="mt-auto"><div class="flex items-end justify-between gap-3"><div><p class="text-2xl font-bold text-brand-700">{{ $offer->price }} <span class="text-sm">TJS</span></p>@if($hasDiscount)<del class="text-sm text-muted">{{ $offer->old_price }} TJS</del>@endif</div><p class="text-sm text-muted">{{ $offer->quantity === null ? 'Остаток уточняется' : ($offer->quantity >= 1 ? 'В наличии: '.(int) $offer->quantity : 'Нет в наличии') }}</p></div>
            <form method="post" action="{{ route('cart.add', $offer) }}" data-cart-form class="mt-3 flex gap-2">@csrf <input type="number" name="quantity" value="1" min="1" max="999" class="min-h-11 w-20 rounded-control border border-slate-300 px-3" aria-label="Количество {{ $offer->medicine->name }}"><x-ui.button class="grow" :disabled="! $canBuy || ! $available">{{ $inCart ? 'Добавить ещё' : ($stale ? 'Требует обновления' : 'В корзину') }}</x-ui.button></form>
        </div>
    </x-ui.card>
@endforeach
