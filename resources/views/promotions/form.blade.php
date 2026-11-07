@extends('layouts.app')
@section('content')
@php
    $restoredForm = is_string(old('_promotion_form')) ? old('_promotion_form') : null;
    $canEdit = ! $promotion->exists || auth()->user()->can('update', $promotion);
    $status = $promotion->status ?? \App\Enums\PromotionStatus::Draft;
    $itemCount = $promotion->exists ? $promotion->items->count() : 0;
    $materialCount = $promotion->exists ? $promotion->attachments->count() : 0;
@endphp
<div data-promotion-editor data-restored-form="{{ $restoredForm }}" class="min-w-0">
    <x-ui.page-header :title="$promotion->exists ? 'Редактирование акции' : 'Новая акция'">
        <x-slot:actions>
            <div class="flex flex-wrap gap-2">
                <x-ui.button :href="route('promotions.manage')" variant="secondary">К списку</x-ui.button>
                @if($promotion->exists)<x-ui.button :href="route('promotions.show', $promotion)" variant="secondary">Предпросмотр</x-ui.button>@endif
            </div>
        </x-slot:actions>
    </x-ui.page-header>
    <div class="mb-6 flex min-w-0 flex-wrap items-center gap-3">
        <p class="min-w-0 break-words text-lg font-semibold">{{ $promotion->title ?: 'Создание черновика' }}</p>
        <x-ui.status-badge :status="$status->value" :label="$status->label()" />
    </div>
    <div class="grid min-w-0 items-start gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
        <aside class="min-w-0 space-y-4 lg:sticky lg:top-6 lg:col-start-2 lg:row-start-1" aria-label="Разделы и публикация">
            <x-ui.card>
                <nav aria-label="Разделы редактора" class="space-y-1">
                    <a href="#information" class="flex min-h-10 items-center justify-between gap-2 rounded-control px-3 text-sm font-medium hover:bg-accent-surface">Основная информация <span aria-hidden="true">↓</span></a>
                    <a href="#products" class="flex min-h-10 items-center justify-between gap-2 rounded-control px-3 text-sm font-medium hover:bg-accent-surface">Товары <span class="text-muted">{{ $itemCount }}</span></a>
                    <a href="#materials" class="flex min-h-10 items-center justify-between gap-2 rounded-control px-3 text-sm font-medium hover:bg-accent-surface">Материалы <span class="text-muted">{{ $materialCount }}</span></a>
                </nav>
            </x-ui.card>
            @include('promotions.publication')
        </aside>
        <div class="min-w-0 space-y-6 lg:col-start-1 lg:row-start-1">
            @if($status === \App\Enums\PromotionStatus::Published && ! auth()->user()->isAdminMode())
                <x-ui.alert type="warning">Изменение акции или её материалов сразу скроет публикацию и вернёт акцию в черновик. После правок отправьте акцию на повторную проверку.</x-ui.alert>
            @endif
            @if(! $canEdit)
                <x-ui.alert type="warning">Акция на проверке. Для редактирования отзовите заявку в черновик.</x-ui.alert>
            @endif
            @include('promotions.information')
            <x-ui.card id="products" class="min-w-0 scroll-mt-6">
                <div class="mb-5 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-xl font-bold">Товары <span class="text-muted">{{ $itemCount }}</span></h2>
                    <p class="text-sm text-muted">Цена и наличие задаются отдельно для акции.</p>
                </div>
                @if($promotion->exists)
                    <div class="divide-y divide-border">
                        @forelse($promotion->items as $item)
                            <details id="product-{{ $item->id }}" @if(old('_promotion_form') === 'item-'.$item->id) open @endif>
                                <summary class="min-h-10 cursor-pointer py-4 text-sm">
                                    <span class="ml-1 inline-grid w-[calc(100%-2rem)] min-w-0 gap-2 align-middle sm:grid-cols-[minmax(0,1fr)_auto]">
                                        <span class="min-w-0 break-words font-semibold">{{ $item->displayName() }}</span>
                                        <span class="font-semibold text-accent">{{ $item->price !== null ? number_format((float) $item->price, 2, '.', ' ').' TJS' : 'Цена по запросу' }}</span>
                                        <span class="text-muted">От {{ $item->minimum_quantity }} шт. · {{ $item->in_stock && $item->stock !== 0 ? 'В наличии' : 'Нет в наличии' }}{{ $item->stock !== null ? ' · Остаток: '.$item->stock : '' }}</span>
                                        <span class="text-muted">{{ $canEdit ? 'Редактировать' : 'Подробнее' }} ↓</span>
                                    </span>
                                </summary>
                                @if($canEdit)
                                    <x-ui.form-scope :form-key="'item-'.$item->id">
                                        <form method="post" action="{{ route('promotions.items.update', [$promotion, $item]) }}" data-promotion-form class="space-y-4 border-t border-border py-4">
                                            @csrf <x-mode-version /> @method('PATCH')
                                            <input type="hidden" name="_promotion_form" value="item-{{ $item->id }}">
                                            @include('promotions.item-fields', ['fieldId' => $item->id, 'copy' => false, 'formKey' => 'item-'.$item->id])
                                            <x-ui.button>Сохранить товар</x-ui.button>
                                        </form>
                                    </x-ui.form-scope>
                                    <form method="post" action="{{ route('promotions.items.destroy', [$promotion, $item]) }}" class="pb-4">
                                        @csrf <x-mode-version /> @method('DELETE')
                                        <x-ui.button variant="danger">Удалить товар</x-ui.button>
                                    </form>
                                @else
                                    <p class="pb-4 text-sm text-muted">{{ $item->price_mode === 'pharmacy_discount' ? 'Применяется дополнительная скидка аптеки.' : 'Окончательная цена.' }} {{ $item->discount_rule }}</p>
                                @endif
                            </details>
                        @empty
                            <x-ui.empty-state title="Пока нет товаров" description="Добавьте товар вручную или скопируйте характеристики из текущего прайс-листа компании." />
                        @endforelse
                    </div>
                    @if($canEdit)
                        @include('promotions.add-products')
                    @endif
                @else
                    <x-ui.empty-state title="Сначала сохраните информацию" description="После создания черновика здесь можно будет добавить товары вручную или из прайс-листа компании." />
                @endif
            </x-ui.card>
            @include('promotions.materials')
        </div>
    </div>
</div>
@endsection
