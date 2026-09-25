@foreach($offers as $offer)
    @php
        $available = $offer->quantity === null || $offer->quantity >= 1;
        $expired = $offer->expires_at?->lt(now()->startOfDay());
        $expiresSoon = $offer->expires_at?->lte(now()->addMonths(config('catalog.expiration_warning_months')));
        $inCart = (int) ($cartQuantities[$offer->id] ?? 0);
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
        <td><form method="post" action="{{ route('cart.add', $offer) }}" data-cart-form>@csrf <div class="flex min-w-44 gap-2"><input type="number" name="quantity" value="1" min="1" max="999" class="min-h-10 w-16 rounded-control border border-slate-300 px-2" aria-label="Количество {{ $offer->medicine->name }}"><x-ui.button class="whitespace-nowrap" :disabled="! $canBuy || ! $available">{{ $inCart ? 'Ещё' : 'В корзину' }}</x-ui.button></div></form></td>
    </tr>
@endforeach
