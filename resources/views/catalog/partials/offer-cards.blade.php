@foreach($offers as $offer)
    <x-ui.card class="flex flex-col gap-4" data-offer-id="{{ $offer->id }}">
        <div>
            <p class="text-lg font-bold text-ink">{{ $offer->medicine->name }}</p>
            <p class="mt-1 text-sm text-slate-600">{{ collect([$offer->medicine->dosage, $offer->medicine->form])->filter()->join(' · ') }}</p>
            <p class="mt-3 text-sm text-muted">{{ collect([$offer->organization->name, $offer->organization->city])->filter()->join(', ') }}@if($offer->medicine->manufacturer) · {{ $offer->medicine->manufacturer }}@endif</p>
        </div>
        <div class="mt-auto flex flex-wrap items-end justify-between gap-3">
            <div><p class="text-2xl font-bold text-brand-700">{{ $offer->price }} <span class="text-sm font-medium">TJS</span></p>@if($offer->old_price)<del class="text-sm text-muted">{{ $offer->old_price }} TJS</del>@endif<p class="mt-1 text-sm text-slate-600">{{ $offer->quantity === null ? 'Количество уточняется' : ($offer->quantity > 0 ? 'В наличии: '.$offer->quantity : 'Нет в наличии') }}</p></div>
            <form method="post" action="{{ route('cart.add', $offer) }}">@csrf <x-ui.button :disabled="$offer->quantity !== null && $offer->quantity <= 0">{{ $offer->quantity !== null && $offer->quantity <= 0 ? 'Нет в наличии' : 'В корзину' }}</x-ui.button></form>
        </div>
        @if($offer->expires_at?->lt(now()->addMonths(5)))<x-ui.alert type="warning">Срок годности менее 5 месяцев</x-ui.alert>@endif
    </x-ui.card>
@endforeach
