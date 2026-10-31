@extends('layouts.app')

@section('content')
<x-ui.page-header title="Партнёры" description="Контакты поставщиков, доступность прайс-листов и согласованные условия для вашей аптеки." />

<x-ui.card>
    <form method="get" action="{{ route('partners.index') }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
        <input type="hidden" name="city" value="{{ request('city') }}">
        <x-ui.input name="name" label="Название поставщика" :value="request('name')" placeholder="Например, Фарма" />
        <x-ui.button aria-label="Найти поставщика" class="!size-11 !min-h-11 !px-0 sm:!w-auto sm:!px-5">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="size-5 sm:hidden"><circle cx="11" cy="11" r="7" stroke-width="2"/><path d="m16.5 16.5 4 4" stroke-linecap="round" stroke-width="2"/></svg>
            <span class="hidden sm:inline">Найти</span>
        </x-ui.button>
    </form>

    <div class="mt-4 border-t border-border pt-4">
        <p id="city-filter-label" class="text-sm font-medium text-content">Город</p>
        <div class="-mx-1 mt-2 flex snap-x gap-2 overflow-x-auto px-1 pb-1" aria-labelledby="city-filter-label">
            <a href="{{ route('partners.index', array_filter(['name' => request('name')])) }}" @class(['inline-flex min-h-10 shrink-0 snap-start items-center rounded-full border px-4 text-sm font-semibold transition focus-visible:outline-focus', 'border-accent-strong bg-action text-white' => ! request()->filled('city'), 'border-control-border bg-surface text-content hover:border-success-control-border hover:bg-accent-surface' => request()->filled('city')]) @if(! request()->filled('city')) aria-current="true" @endif>Все города</a>
            @foreach($cities as $city)
                <a href="{{ route('partners.index', array_filter(['name' => request('name'), 'city' => $city])) }}" @class(['inline-flex min-h-10 shrink-0 snap-start items-center rounded-full border px-4 text-sm font-semibold transition focus-visible:outline-focus', 'border-accent-strong bg-action text-white' => request('city') === $city, 'border-control-border bg-surface text-content hover:border-success-control-border hover:bg-accent-surface' => request('city') !== $city]) @if(request('city') === $city) aria-current="true" @endif>{{ $city }}</a>
            @endforeach
        </div>
    </div>
</x-ui.card>

<div class="mt-6">
    <h2 class="text-lg font-bold text-ink">Список поставщиков</h2>
    <p class="mt-1 text-sm text-muted">Найдено: {{ $suppliers->total() }}</p>
</div>

<div class="mt-3 grid gap-3">
@forelse($suppliers as $supplier)
    @php
        $discount = $discounts->get($supplier->id);
        $effectiveDiscount = app(\App\Services\SupplierDiscountPrice::class)->selectedPercent($discount?->supplier_discount_percent, $supplier->default_discount_percent);
        $activeImport = $supplier->activePriceListImport;
        $activeImportUpdatedAt = $activeImport?->activated_at;
        $email = $supplier->contact_email ?: $supplier->senderAddresses->first()?->email;
        $isValidEmail = $email && filter_var($email, FILTER_VALIDATE_EMAIL);
        $storedPhones = array_values(array_filter(array_merge([$supplier->phone], $supplier->additional_phones ?? [])));
        $validPhones = collect($storedPhones)->map(fn ($phone) => \App\Support\PhoneNormalizer::normalize($phone))->filter()->unique()->values();
        $whatsAppPhone = \App\Support\PhoneNormalizer::normalize($supplier->whatsapp_phone);
        $hasContacts = $validPhones->isNotEmpty() || $whatsAppPhone || $isValidEmail;
        $initial = str($supplier->name)->trim()->substr(0, 1)->upper();
    @endphp
    <x-ui.card class="relative cursor-pointer !p-4 transition hover:border-success-border hover:shadow-md sm:!p-5">
        <button type="button" class="absolute inset-0 z-0 rounded-panel focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus" aria-label="Открыть сведения о поставщике {{ $supplier->name }}" aria-controls="partner-{{ $supplier->id }}" data-dialog-open></button>
        <div class="flex items-start gap-3 sm:gap-4">
            <div class="grid size-12 shrink-0 place-items-center rounded-xl border border-accent-border bg-accent-surface text-lg font-bold text-accent" aria-hidden="true">{{ $initial }}</div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h3 class="truncate text-lg font-bold text-ink">{{ $supplier->name }}</h3>
                        <p class="mt-1 text-sm text-muted">{{ $supplier->city ?: 'Город не указан' }}</p>
                        <p class="mt-1 text-xs text-muted">{{ $activeImportUpdatedAt ? 'Прайс обновлён '.$activeImportUpdatedAt->timezone('Asia/Dushanbe')->format('d.m.Y H:i') : ($activeImport ? 'Время активации не указано' : 'Активный прайс-лист не загружен') }}</p>
                    </div>
                    <div class="flex w-fit shrink-0 flex-wrap gap-2">
                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $supplier->has_available_catalog ? 'bg-accent-surface text-accent ring-success-border' : 'bg-surface-hover text-secondary ring-border' }}">
                            {{ $supplier->has_available_catalog ? 'Прайс доступен' : 'Прайс недоступен' }}
                        </span>
                        <span class="text-xs text-muted">Скидка по умолчанию {{ $supplier->default_discount_percent }}% · Применяется {{ $effectiveDiscount }}%</span>
                        @if($discount)
                            <span class="inline-flex rounded-full bg-warning-surface px-2.5 py-1 text-xs font-semibold text-warning-strong ring-1 ring-inset ring-warning-border">Согласованная скидка {{ $discount->supplier_discount_percent }}%</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-4 border-t border-border pt-4">
            @if($hasContacts)
                <div class="relative z-10 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                    @foreach($validPhones as $phone)
                        <a href="tel:{{ $phone }}" class="inline-flex min-h-10 min-w-0 items-center justify-center gap-2 rounded-control border border-border bg-surface-subtle px-3 text-sm font-medium text-content transition hover:border-success-border hover:bg-accent-surface hover:text-accent">
                            <span aria-hidden="true">☎</span><span class="truncate">{{ $phone }}</span>
                        </a>
                    @endforeach
                    @if($whatsAppPhone)
                        <a href="https://wa.me/{{ ltrim($whatsAppPhone, '+') }}" class="inline-flex min-h-10 min-w-0 items-center justify-center gap-2 rounded-control border border-border bg-surface-subtle px-3 text-sm font-medium text-content transition hover:border-success-border hover:bg-accent-surface hover:text-accent">
                            <span aria-hidden="true">◉</span><span class="truncate">WhatsApp</span>
                        </a>
                    @endif
                    @if($isValidEmail)
                        <a href="mailto:{{ $email }}" class="inline-flex min-h-10 min-w-0 items-center justify-center gap-2 rounded-control border border-border bg-surface-subtle px-3 text-sm font-medium text-content transition hover:border-success-border hover:bg-accent-surface hover:text-accent">
                            <span aria-hidden="true">✉</span><span class="truncate">{{ $email }}</span>
                        </a>
                    @endif
                </div>
            @else
                <p class="text-sm text-muted">Контакты не указаны</p>
            @endif
        </div>
    </x-ui.card>

    <x-ui.dialog name="partner-{{ $supplier->id }}" :open-on-load="$errors->has('supplier_discount_percent') && (int) old('supplier_id') === $supplier->id" class="!w-[min(28rem,calc(100%-2rem))]">
        <x-slot:header class="border-b-0 pb-3">
            <div class="flex min-w-0 items-center gap-3">
                <div class="grid size-11 shrink-0 place-items-center rounded-xl border border-accent-border bg-accent-surface text-base font-bold text-accent" aria-hidden="true">{{ $initial }}</div>
                <div class="min-w-0"><h2 id="partner-{{ $supplier->id }}-title" class="truncate text-lg font-bold">{{ $supplier->name }}</h2><p class="mt-0.5 truncate text-sm text-muted">{{ $supplier->city ?: 'Город не указан' }}</p></div>
            </div>
            <x-ui.icon-button label="Закрыть" data-dialog-close>×</x-ui.icon-button>
        </x-slot:header>

        <div class="min-h-0 space-y-3 overflow-y-auto px-5 pb-5">
            <x-ui.card class="!p-4">
                <p class="text-sm text-muted">Доступность прайса</p>
                <p class="mt-1 font-semibold {{ $supplier->has_available_catalog ? 'text-accent' : 'text-content' }}">{{ $supplier->has_available_catalog ? 'Прайс открыт' : 'Прайс закрыт' }}</p>
                <p class="mt-1 text-xs text-muted">{{ $supplier->has_available_catalog ? 'Актуальные предложения доступны для просмотра.' : 'Актуальные предложения пока недоступны.' }}</p>
            </x-ui.card>

            <x-ui.card class="!p-4">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-muted">Город</dt><dd class="mt-1 font-medium">{{ $supplier->city ?: 'Не указан' }}</dd></div>
                    <div><dt class="text-muted">Обновление прайс-листа</dt><dd class="mt-1 font-medium">{{ $activeImportUpdatedAt ? $activeImportUpdatedAt->timezone('Asia/Dushanbe')->format('d.m.Y H:i') : ($activeImport ? 'Время активации не указано' : 'Активный прайс-лист не загружен') }}</dd></div>
                    <div><dt class="text-muted">Срок доступа</dt><dd class="mt-1 font-medium">Без ограничения по сроку</dd></div>
                </dl>
            </x-ui.card>

            @if($hasContacts || $supplier->contact_name)
                <div>
                    <x-ui.button type="button" variant="secondary" class="w-full" aria-controls="partner-{{ $supplier->id }}-contacts" aria-expanded="false" data-contact-toggle data-show-label="Показать контакты" data-hide-label="Скрыть контакты">Показать контакты</x-ui.button>
                    <div id="partner-{{ $supplier->id }}-contacts" class="mt-3 space-y-2" data-contact-details hidden>
                        @if($supplier->contact_name)<p class="text-sm"><span class="text-muted">Контактное лицо:</span> <span class="font-medium">{{ $supplier->contact_name }}</span></p>@endif
                        @foreach($validPhones as $phone)<a href="tel:{{ $phone }}" class="flex min-h-10 items-center rounded-control border border-border px-3 text-sm font-medium text-content hover:border-success-border hover:bg-accent-surface hover:text-accent">{{ $phone }}</a>@endforeach
                        @if($whatsAppPhone)<a href="https://wa.me/{{ ltrim($whatsAppPhone, '+') }}" class="flex min-h-10 items-center rounded-control border border-border px-3 text-sm font-medium text-content hover:border-success-border hover:bg-accent-surface hover:text-accent">WhatsApp</a>@endif
                        @if($isValidEmail)<a href="mailto:{{ $email }}" class="flex min-h-10 items-center rounded-control border border-border px-3 text-sm font-medium text-content hover:border-success-border hover:bg-accent-surface hover:text-accent">{{ $email }}</a>@endif
                    </div>
                </div>
            @endif

            <form method="post" action="{{ route('partners.discount', $supplier) }}" class="space-y-3">
                @csrf <x-mode-version />
                @method('PATCH')
                <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">
                <p class="text-sm text-muted">Скидка по умолчанию: {{ $supplier->default_discount_percent }}%. Согласованная: {{ $discount?->supplier_discount_percent ?? '—' }}{{ $discount ? '%' : '' }}. Применяется: {{ $effectiveDiscount }}%.</p>
                <x-ui.input id="supplier-discount-percent-{{ $supplier->id }}" name="supplier_discount_percent" type="number" min="0" max="100" step="0.01" label="Согласованная скидка от поставщика, %" :value="old('supplier_discount_percent', $discount?->supplier_discount_percent)" hint="Согласованная скидка заменяет скидку по умолчанию и применяется к текущей цене, включая акционную. Пустое поле удаляет договорённость и возвращает скидку по умолчанию. Скидка 0% отменяет скидку поставщика." />
                <x-ui.button>Сохранить скидку</x-ui.button>
            </form>
        </div>
    </x-ui.dialog>
@empty
    <x-ui.empty-state title="Поставщики не найдены" description="Измените название или выберите другой город.">
        @if(request()->filled('name') || request()->filled('city'))
            <x-slot:action><x-ui.button href="{{ route('partners.index') }}" variant="secondary">Сбросить фильтры</x-ui.button></x-slot:action>
        @endif
    </x-ui.empty-state>
@endforelse
</div>

@if($suppliers->total() > 0)
    @php
        $paginationPages = collect([1, $suppliers->lastPage()])
            ->merge(range(max(1, $suppliers->currentPage() - 2), min($suppliers->lastPage(), $suppliers->currentPage() + 2)))
            ->unique()->sort()->values();
    @endphp
    <x-ui.card class="mt-6 !p-4 sm:!p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-muted">
                Показаны <span class="font-semibold text-ink">{{ $suppliers->firstItem() }}–{{ $suppliers->lastItem() }}</span> из <span class="font-semibold text-ink">{{ $suppliers->total() }}</span>
                <span class="mt-1 block sm:ml-2 sm:inline">Страница {{ $suppliers->currentPage() }} из {{ $suppliers->lastPage() }}</span>
            </p>
            @if($suppliers->lastPage() > 1)
                <nav class="flex flex-col gap-2 sm:flex-row sm:items-center" aria-label="Страницы поставщиков">
                    <div class="grid grid-cols-2 gap-2 sm:contents">
                        @if($suppliers->onFirstPage())
                            <span class="inline-flex min-h-10 items-center justify-center rounded-control border border-border px-3 text-sm font-semibold text-placeholder">Назад</span>
                        @else
                            <a href="{{ $suppliers->previousPageUrl() }}" class="inline-flex min-h-10 items-center justify-center rounded-control border border-control-border bg-surface px-3 text-sm font-semibold text-content hover:bg-surface-subtle">Назад</a>
                        @endif
                        @if($suppliers->hasMorePages())
                            <a href="{{ $suppliers->nextPageUrl() }}" class="inline-flex min-h-10 items-center justify-center rounded-control border border-control-border bg-surface px-3 text-sm font-semibold text-content hover:bg-surface-subtle sm:order-3">Вперёд</a>
                        @else
                            <span class="inline-flex min-h-10 items-center justify-center rounded-control border border-border px-3 text-sm font-semibold text-placeholder sm:order-3">Вперёд</span>
                        @endif
                    </div>
                    <div class="flex flex-wrap justify-center gap-1 sm:order-2">
                        @foreach($paginationPages as $page)
                            @if(! $loop->first && $page - $paginationPages[$loop->index - 1] > 1)<span class="grid size-10 place-items-center text-sm text-muted" aria-hidden="true">…</span>@endif
                            <a href="{{ $suppliers->url($page) }}" @class(['grid size-10 place-items-center rounded-control border text-sm font-semibold transition', 'border-accent-strong bg-action text-white' => $page === $suppliers->currentPage(), 'border-control-border bg-surface text-content hover:bg-surface-subtle' => $page !== $suppliers->currentPage()]) @if($page === $suppliers->currentPage()) aria-current="page" @endif aria-label="Страница {{ $page }}">{{ $page }}</a>
                        @endforeach
                    </div>
                </nav>
            @endif
        </div>
    </x-ui.card>
@endif

@endsection
