@extends('layouts.app')
@section('content')
<x-ui.page-header :title="$promotion->title" :description="$promotion->organization->name.' · '.$promotion->starts_on->format('d.m.Y').' — '.$promotion->ends_on->format('d.m.Y')">
    <x-slot:actions><div class="flex flex-wrap gap-2"><x-ui.button :href="route('promotions.index')" variant="secondary">Все акции</x-ui.button>@can('manage', $promotion)<x-ui.button :href="route('promotions.edit', $promotion)">Управление</x-ui.button>@endcan</div></x-slot:actions>
</x-ui.page-header>
@if(! $promotion->isActive())<x-ui.alert type="warning" class="mb-4">{{ $promotion->ends_on->toDateString() < now('Asia/Dushanbe')->toDateString() ? 'Акция завершена.' : 'Акция сейчас не действует.' }} Покупка недоступна.</x-ui.alert>@endif
<x-ui.card class="mb-5 space-y-4">
    @if($promotion->organization->logo_path)<img src="{{ route('promotions.logo', $promotion->organization) }}" alt="Логотип {{ $promotion->organization->name }}" class="h-16 max-w-full object-contain">@endif
    <p class="whitespace-pre-line break-words">{{ $promotion->description }}</p>
    @if($promotion->conditions)<div><h2 class="font-bold">Условия</h2><p class="whitespace-pre-line break-words">{{ $promotion->conditions }}</p></div>@endif
    <p class="text-sm text-muted">Город: {{ $promotion->city ?: 'Все города' }}</p>
    <div class="flex flex-wrap items-center gap-2">
        @if($whatsappUrl)<x-ui.button :href="$whatsappUrl" target="_blank" rel="noopener noreferrer" variant="secondary">WhatsApp</x-ui.button>@else<x-ui.button type="button" disabled variant="secondary" aria-describedby="no-whatsapp">WhatsApp</x-ui.button><span id="no-whatsapp" class="text-sm text-muted">Компания не указала номер WhatsApp.</span>@endif
        <x-ui.button type="button" variant="secondary" data-promotion-share data-url="{{ route('promotions.show', $promotion) }}" data-title="{{ $promotion->title }}">Поделиться</x-ui.button><span data-promotion-share-status role="status" class="text-sm text-muted"></span>
    </div>
</x-ui.card>
<div class="grid gap-4 lg:grid-cols-2">
@foreach($promotion->attachments as $attachment)
    <x-ui.card class="min-w-0 space-y-3">
        <h2 class="break-words font-semibold">{{ $attachment->original_name }}</h2>
        @if(str_starts_with($attachment->mime_type, 'image/'))
            <img loading="lazy" src="{{ route('promotions.attachments.show', $attachment) }}" alt="{{ $attachment->original_name }}" class="max-h-96 w-full object-contain">
        @elseif($attachment->mime_type === 'video/mp4')
            <video controls preload="metadata" class="max-h-96 w-full" aria-label="{{ $attachment->original_name }}"><source src="{{ route('promotions.attachments.show', $attachment) }}" type="video/mp4"></video>
        @elseif($attachment->mime_type === 'application/pdf')
            <div data-promotion-pdf data-url="{{ route('promotions.attachments.show', $attachment) }}" class="space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.button type="button" variant="secondary" data-pdf-prev disabled>Назад</x-ui.button><span data-pdf-page aria-live="polite">Загрузка…</span><x-ui.button type="button" variant="secondary" data-pdf-next disabled>Вперёд</x-ui.button>
                    <x-ui.button type="button" variant="ghost" data-pdf-out disabled aria-label="Уменьшить масштаб PDF">−</x-ui.button><x-ui.button type="button" variant="ghost" data-pdf-in disabled aria-label="Увеличить масштаб PDF">+</x-ui.button><x-ui.button type="button" variant="secondary" data-pdf-fit disabled>По ширине</x-ui.button>
                </div>
                <p data-pdf-error role="alert" class="text-danger" hidden>Не удалось открыть PDF. Скачайте оригинал по ссылке ниже.</p>
                <div data-pdf-container class="max-h-[70vh] overflow-auto"><canvas data-pdf-canvas role="img" aria-label="Страница PDF"></canvas></div>
            </div>
        @endif
        <x-ui.button :href="route('promotions.attachments.show', [$attachment, 'download' => 1])" variant="secondary">Скачать оригинал</x-ui.button>
    </x-ui.card>
@endforeach
</div>
<h2 class="mb-3 mt-6 text-xl font-bold">Товарные позиции</h2>
@can('buy', $promotion)
    <form id="promotion-order" method="post" action="{{ route('cart.promotions.add', $promotion) }}" data-promotion-order>@csrf <x-mode-version /><input type="hidden" name="open_cart" value="1"><x-ui.button>Заказать выбранные позиции</x-ui.button><p data-promotion-selection-error role="alert" class="text-danger" hidden>Выберите хотя бы одну позицию.</p></form>
@endcan
<div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
@forelse($promotion->items as $item)
    <x-ui.card class="space-y-3">
        <h3 class="break-words font-bold">{{ $item->displayName() }}</h3>
        <p class="text-lg font-bold text-accent">{{ $item->price !== null ? $item->price.' TJS' : 'Цена по запросу' }}</p>
        <p class="text-sm">Минимум: {{ $item->minimum_quantity }} шт. · {{ $item->in_stock && $item->stock !== 0 ? 'В наличии' : 'Нет в наличии' }}{{ $item->stock !== null ? ' · Остаток: '.$item->stock : '' }}</p>
        <p class="whitespace-pre-line break-words text-sm">{{ $item->discount_rule }}</p>
        @if($item->price_mode === 'pharmacy_discount')<p class="text-sm text-muted">Применяется дополнительная скидка аптеки. Итоговая цена — в корзине.</p>@else<p class="text-sm text-muted">Окончательная цена</p>@endif
        @can('buy', $promotion)
            @if($item->price === null)
                <a class="inline-flex min-h-10 items-center text-accent underline" href="#quote-form" data-quote-item="{{ $item->id }}">Запросить цену</a>
            @elseif($item->availabilityReason($item->minimum_quantity) === null)
                <x-ui.checkbox :name="'selected['.$item->id.']'" :id="'promotion-selected-'.$item->id" :value="$item->id" label="Выбрать для заказа" form="promotion-order" data-promotion-selection />
                <form method="post" action="{{ route('cart.promotions.add', $promotion) }}" class="space-y-2">
                    @csrf <x-mode-version /><input type="hidden" name="items[0][id]" value="{{ $item->id }}">
                    <x-ui.input type="number" name="items[0][quantity]" :id="'promotion-quantity-'.$item->id" label="Количество" :value="$item->minimum_quantity" :min="$item->minimum_quantity" :max="$item->stock ?? 999999" data-promotion-quantity="{{ $item->id }}" required />
                    <div class="flex flex-wrap gap-2"><x-ui.button>В корзину</x-ui.button><x-ui.button name="open_cart" value="1" variant="secondary">Заказать</x-ui.button></div>
                </form>
            @endif
        @endcan
    </x-ui.card>
@empty
    <x-ui.empty-state title="Позиции пока не добавлены" description="Условия предложения можно уточнить у компании." />
@endforelse
</div>
@can('buy', $promotion)
<x-ui.card class="mt-6">
    <form id="quote-form" method="post" action="{{ route('promotion-quotes.store', $promotion) }}" class="space-y-3">
        @csrf <x-mode-version /><h2 class="text-lg font-bold">Запросить цену</h2>
        <p class="text-sm text-muted">Запрос сохраняется на сайте. Дальнейшее общение проходит вне сайта.</p>
        <x-ui.select name="promotion_item_id" label="Позиция"><option value="">Вся акция</option>@foreach($promotion->items as $item)<option value="{{ $item->id }}">{{ $item->displayName() }}</option>@endforeach</x-ui.select>
        <x-ui.textarea name="message" label="Ваш вопрос" rows="3" maxlength="5000" required />
        <x-ui.button>Отправить запрос</x-ui.button>
    </form>
</x-ui.card>
@endcan
@endsection
