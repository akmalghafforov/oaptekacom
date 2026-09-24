@extends('layouts.app')

@section('content')
<x-ui.page-header title="Партнёры" description="Контакты поставщиков, доступность прайс-листов и согласованные условия для вашей аптеки.">
    <x-slot:actions>
        <x-ui.button type="button" aria-label="Добавить поставщика" aria-controls="add-partner" data-dialog-open class="!size-11 !min-h-11 !rounded-full !px-0 text-xl">
            <span aria-hidden="true">+</span>
        </x-ui.button>
    </x-slot:actions>
</x-ui.page-header>

<x-ui.card>
    <form method="get" action="{{ route('partners.index') }}" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
        <input type="hidden" name="city" value="{{ request('city') }}">
        <x-ui.input name="name" label="Название поставщика" :value="request('name')" placeholder="Например, Фарма" />
        <x-ui.button aria-label="Найти поставщика" class="!size-11 !min-h-11 !px-0 sm:!w-auto sm:!px-5">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="size-5 sm:hidden"><circle cx="11" cy="11" r="7" stroke-width="2"/><path d="m16.5 16.5 4 4" stroke-linecap="round" stroke-width="2"/></svg>
            <span class="hidden sm:inline">Найти</span>
        </x-ui.button>
    </form>

    <div class="mt-4 border-t border-slate-200 pt-4">
        <p id="city-filter-label" class="text-sm font-medium text-slate-700">Город</p>
        <div class="-mx-1 mt-2 flex snap-x gap-2 overflow-x-auto px-1 pb-1" aria-labelledby="city-filter-label">
            <a href="{{ route('partners.index', array_filter(['name' => request('name')])) }}" @class(['inline-flex min-h-10 shrink-0 snap-start items-center rounded-full border px-4 text-sm font-semibold transition focus-visible:outline-brand-600', 'border-brand-600 bg-brand-600 text-white' => ! request()->filled('city'), 'border-slate-300 bg-white text-slate-700 hover:border-brand-300 hover:bg-brand-50' => request()->filled('city')]) @if(! request()->filled('city')) aria-current="true" @endif>Все города</a>
            @foreach($cities as $city)
                <a href="{{ route('partners.index', array_filter(['name' => request('name'), 'city' => $city])) }}" @class(['inline-flex min-h-10 shrink-0 snap-start items-center rounded-full border px-4 text-sm font-semibold transition focus-visible:outline-brand-600', 'border-brand-600 bg-brand-600 text-white' => request('city') === $city, 'border-slate-300 bg-white text-slate-700 hover:border-brand-300 hover:bg-brand-50' => request('city') !== $city]) @if(request('city') === $city) aria-current="true" @endif>{{ $city }}</a>
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
        $link = $links->get($supplier->id);
        $latestImport = $supplier->priceListImports->first();
        $activeImport = $supplier->activePriceListImport;
        $activeImportUpdatedAt = $activeImport ? ($activeImport->received_at ?? $activeImport->activated_at ?? $activeImport->created_at) : null;
        $email = $supplier->contact_email ?: $supplier->senderAddresses->first()?->email;
        $isValidEmail = $email && filter_var($email, FILTER_VALIDATE_EMAIL);
        $storedPhones = array_values(array_filter(array_merge([$supplier->phone], $supplier->additional_phones ?? [])));
        $validPhones = collect($storedPhones)->map(fn ($phone) => \App\Support\PhoneNormalizer::normalize($phone))->filter()->unique()->values();
        $whatsAppPhone = \App\Support\PhoneNormalizer::normalize($supplier->whatsapp_phone);
        $hasContacts = $validPhones->isNotEmpty() || $whatsAppPhone || $isValidEmail;
        $initial = str($supplier->name)->trim()->substr(0, 1)->upper();
    @endphp
    <x-ui.card class="!p-4 sm:!p-5">
        <div class="flex items-start gap-3 sm:gap-4">
            <div class="grid size-12 shrink-0 place-items-center rounded-xl border border-brand-100 bg-brand-50 text-lg font-bold text-brand-700" aria-hidden="true">{{ $initial }}</div>
            <div class="min-w-0 flex-1">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h3 class="truncate text-lg font-bold text-ink">{{ $supplier->name }}</h3>
                        <p class="mt-1 text-sm text-muted">{{ $supplier->city ?: 'Город не указан' }}</p>
                        <p class="mt-1 text-xs text-muted">{{ $activeImportUpdatedAt ? 'Прайс обновлён '.$activeImportUpdatedAt->timezone('Asia/Dushanbe')->format('d.m.Y H:i') : 'Активный прайс-лист не загружен' }}</p>
                    </div>
                    <span class="inline-flex w-fit shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $supplier->has_available_catalog ? 'bg-brand-50 text-brand-700 ring-brand-200' : 'bg-slate-100 text-slate-600 ring-slate-200' }}">
                        {{ $supplier->has_available_catalog ? 'Прайс доступен' : 'Прайс недоступен' }}
                    </span>
                </div>
            </div>
        </div>

        <div class="mt-4 border-t border-slate-200 pt-4">
            @if($hasContacts)
                <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                    @foreach($validPhones as $phone)
                        <a href="tel:{{ $phone }}" class="inline-flex min-h-10 min-w-0 items-center justify-center gap-2 rounded-control border border-slate-200 bg-slate-50 px-3 text-sm font-medium text-slate-700 transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700">
                            <span aria-hidden="true">☎</span><span class="truncate">{{ $phone }}</span>
                        </a>
                    @endforeach
                    @if($whatsAppPhone)
                        <a href="https://wa.me/{{ ltrim($whatsAppPhone, '+') }}" class="inline-flex min-h-10 min-w-0 items-center justify-center gap-2 rounded-control border border-slate-200 bg-slate-50 px-3 text-sm font-medium text-slate-700 transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700">
                            <span aria-hidden="true">◉</span><span class="truncate">WhatsApp</span>
                        </a>
                    @endif
                    @if($isValidEmail)
                        <a href="mailto:{{ $email }}" class="inline-flex min-h-10 min-w-0 items-center justify-center gap-2 rounded-control border border-slate-200 bg-slate-50 px-3 text-sm font-medium text-slate-700 transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700">
                            <span aria-hidden="true">✉</span><span class="truncate">{{ $email }}</span>
                        </a>
                    @endif
                </div>
            @else
                <p class="text-sm text-muted">Контакты не указаны</p>
            @endif

            <div class="mt-4 flex justify-end">
                <x-ui.button type="button" variant="secondary" aria-controls="partner-{{ $supplier->id }}" data-dialog-open>Подробнее</x-ui.button>
            </div>
        </div>
    </x-ui.card>

    <x-ui.dialog name="partner-{{ $supplier->id }}" :title="$supplier->name" :description="$supplier->city ?: 'Город не указан'" :open-on-load="$errors->has('discount_percent') && (int) old('supplier_id') === $supplier->id">
        <div class="min-h-0 overflow-y-auto p-5">
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-muted">Контактное лицо</dt><dd class="mt-1 font-medium">{{ $supplier->contact_name ?: '—' }}</dd></div>
                <div><dt class="text-muted">Партнёрство</dt><dd class="mt-1 font-medium">{{ $link ? 'Добавлен в партнёры' : 'Не добавлен' }}</dd></div>
                <div><dt class="text-muted">Телефоны</dt><dd class="mt-1 break-words font-medium">{{ $storedPhones ? implode(', ', $storedPhones) : '—' }}</dd></div>
                <div><dt class="text-muted">WhatsApp</dt><dd class="mt-1 break-words font-medium">{{ $supplier->whatsapp_phone ?: '—' }}</dd></div>
                <div><dt class="text-muted">Email</dt><dd class="mt-1 break-words font-medium">{{ $email ?: '—' }}</dd></div>
                <div><dt class="text-muted">Доступность прайса</dt><dd class="mt-1 font-medium">{{ $supplier->has_available_catalog ? 'Прайс доступен' : 'Прайс недоступен' }}</dd></div>
                <div><dt class="text-muted">Последний импорт</dt><dd class="mt-1 font-medium">{{ $latestImport ? ($latestImport->received_at ?? $latestImport->created_at)->timezone('Asia/Dushanbe')->format('d.m.Y H:i') : '—' }}</dd></div>
                <div><dt class="text-muted">Статус импорта</dt><dd class="mt-1">@if($latestImport)<x-ui.status-badge :status="$latestImport->status->value" :label="$latestImport->status->label()" />@else<span class="font-medium">Нет импортов</span>@endif</dd></div>
                <div><dt class="text-muted">Согласованная скидка</dt><dd class="mt-1 font-semibold text-brand-700">{{ $link?->discount_percent !== null ? number_format((float) $link->discount_percent, 2, '.', '').'%' : 'Не указана' }}</dd></div>
            </dl>

            @if($link)
                <form method="post" action="{{ route('partners.discount', $supplier) }}" class="mt-5 space-y-3 border-t border-slate-200 pt-5">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">
                    <x-ui.input id="discount-percent-{{ $supplier->id }}" name="discount_percent" type="number" min="0" max="100" step="0.01" label="Согласованная скидка, %" :value="$link->discount_percent" required />
                    <p class="text-xs text-muted">Скидка сохраняется как договорённость и пока не меняет цены в каталоге и при оформлении заказа.</p>
                    <x-ui.button>Сохранить скидку</x-ui.button>
                </form>
            @else
                <p class="mt-5 border-t border-slate-200 pt-5 text-sm text-muted">Добавьте поставщика по телефону организации или коду приглашения, чтобы указать скидку.</p>
            @endif
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
                            <span class="inline-flex min-h-10 items-center justify-center rounded-control border border-slate-200 px-3 text-sm font-semibold text-slate-400">Назад</span>
                        @else
                            <a href="{{ $suppliers->previousPageUrl() }}" class="inline-flex min-h-10 items-center justify-center rounded-control border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Назад</a>
                        @endif
                        @if($suppliers->hasMorePages())
                            <a href="{{ $suppliers->nextPageUrl() }}" class="inline-flex min-h-10 items-center justify-center rounded-control border border-slate-300 bg-white px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:order-3">Вперёд</a>
                        @else
                            <span class="inline-flex min-h-10 items-center justify-center rounded-control border border-slate-200 px-3 text-sm font-semibold text-slate-400 sm:order-3">Вперёд</span>
                        @endif
                    </div>
                    <div class="flex flex-wrap justify-center gap-1 sm:order-2">
                        @foreach($paginationPages as $page)
                            @if(! $loop->first && $page - $paginationPages[$loop->index - 1] > 1)<span class="grid size-10 place-items-center text-sm text-muted" aria-hidden="true">…</span>@endif
                            <a href="{{ $suppliers->url($page) }}" @class(['grid size-10 place-items-center rounded-control border text-sm font-semibold transition', 'border-brand-600 bg-brand-600 text-white' => $page === $suppliers->currentPage(), 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' => $page !== $suppliers->currentPage()]) @if($page === $suppliers->currentPage()) aria-current="page" @endif aria-label="Страница {{ $page }}">{{ $page }}</a>
                        @endforeach
                    </div>
                </nav>
            @endif
        </div>
    </x-ui.card>
@endif

<x-ui.dialog name="add-partner" title="Добавить поставщика" description="Найдите организацию по телефону или используйте код приглашения." :open-on-load="$errors->has('phone') || $errors->has('code')">
    <div class="min-h-0 overflow-y-auto p-5">
        <form method="post" action="{{ route('partners.phone') }}" class="space-y-3">
            @csrf
            <x-ui.input name="phone" label="Телефон организации" placeholder="+992901234567" required />
            <x-ui.button class="w-full">Добавить по телефону</x-ui.button>
        </form>
        <form method="post" action="{{ route('partners.code') }}" class="mt-5 space-y-3 border-t border-slate-200 pt-5">
            @csrf
            <x-ui.input name="code" label="Код приглашения" required />
            <x-ui.button variant="secondary" class="w-full">Добавить по коду</x-ui.button>
        </form>
    </div>
</x-ui.dialog>
@endsection
