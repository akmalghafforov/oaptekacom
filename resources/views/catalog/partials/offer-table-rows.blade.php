@foreach($offers as $offer)
    @php
        $updatedAt = $offer->import?->inventory_at ?? $offer->import?->activated_at ?? $offer->import?->updated_at;
        $stale = ! $updatedAt || $updatedAt->lt(now()->subHours(config('catalog.supplier_stale_hours')));
        $available = ($offer->quantity === null || $offer->quantity >= 1) && ! $stale;
        $inCart = (int) ($cartQuantities[$offer->id] ?? 0);
    @endphp
    <tr data-offer-id="{{ $offer->id }}">
        <td><img src="{{ asset('images/catalog/product-placeholder.svg') }}" alt="" class="size-16 rounded-control object-cover"></td>
        <td><p class="font-bold text-ink">{{ $offer->medicine->name }}</p><p class="mt-1 text-xs text-muted">{{ collect([$offer->medicine->dosage, $offer->medicine->form])->filter()->join(' · ') ?: 'Форма не указана' }}</p></td>
        <td>{{ $offer->medicine->manufacturer ?: '—' }}<span class="sr-only">{{ $offer->medicine->manufacturer ? '' : 'Производитель не указан' }}</span></td>
        <td>{{ $offer->expires_at?->format('d.m.Y') ?: '—' }}</td>
        <td><p class="font-medium">{{ $offer->organization->name }}</p><p class="text-xs text-muted">{{ $offer->organization->city ?: 'Город не указан' }}</p><p class="mt-1 text-xs {{ $stale ? 'text-warning' : 'text-brand-700' }}">{{ $stale ? 'Данные требуют обновления' : 'Обновлено '.$updatedAt?->diffForHumans() }}</p></td>
        <td class="whitespace-nowrap"><strong class="text-lg text-brand-700">{{ $offer->price }} TJS</strong>@if($offer->old_price > $offer->price)<del class="block text-xs text-muted">{{ $offer->old_price }} TJS</del>@endif</td>
        <td><form method="post" action="{{ route('cart.add', $offer) }}" data-cart-form>@csrf <div class="flex min-w-44 gap-2"><input type="number" name="quantity" value="1" min="1" max="999" class="min-h-10 w-16 rounded-control border border-slate-300 px-2" aria-label="Количество {{ $offer->medicine->name }}"><x-ui.button class="whitespace-nowrap" :disabled="! $canBuy || ! $available">{{ $inCart ? 'Ещё' : 'В корзину' }}</x-ui.button></div></form></td>
    </tr>
@endforeach
