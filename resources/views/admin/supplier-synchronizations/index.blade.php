@extends('layouts.app')
@section('content')
@php
    $serviceNames = ['icorp' => 'iCorp', 'pricelist_tj' => 'PriceList-TJ'];
    $statusLabels = ['connected' => 'Подключено', 'unknown' => 'Не проверено', 'authentication' => 'Требуется авторизация', 'permission' => 'Нет доступа', 'transport' => 'Ошибка соединения', 'extraction' => 'Ошибка чтения данных', 'takeover_required' => 'Аккаунт занят на другом устройстве'];
@endphp
<x-ui.page-header title="Синхронизация поставщиков" description="Состояние сервисов, справочники и настройка загрузки прайс-листов.">
    <x-slot:actions><x-ui.button :href="request()->fullUrl()" variant="secondary">Обновить статус</x-ui.button></x-slot:actions>
</x-ui.page-header>
<div class="space-y-6">
    @if($success = session('mapping_success'))
        <x-ui.alert type="success">
            Сопоставление подтверждено: {{ $success['name'] }}.
            <a class="font-semibold underline" href="{{ route('admin.supplier-import-profiles.edit', $success['id']) }}">Профиль импорта</a>
            @if($success['removed'])<p class="mt-2">Подтверждённая запись больше не соответствует текущим фильтрам и исключена из результатов.</p>@endif
        </x-ui.alert>
    @endif
    <div class="grid gap-4 md:grid-cols-2">
        @foreach($statuses as $source => $status)
            <x-ui.card>
                <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-bold">{{ $serviceNames[$source] }}</h2><x-ui.status-badge :status="$status['status'] === 'connected' ? 'confirmed' : 'pending'" :label="$statusLabels[$status['status']] ?? 'Требуется проверка'" /></div>
                <p class="mt-3 text-sm text-muted">Проверка: {{ isset($status['checked_at']) ? \Carbon\CarbonImmutable::parse($status['checked_at'])->timezone('Asia/Dushanbe')->format('d.m.Y H:i') : 'Ещё не проверено' }} (Душанбе)</p>
                <p class="mt-1 text-sm">В справочнике: {{ $directoryCounts[$source] ?? 0 }} поставщиков</p>
                @if($source === 'icorp')
                    <form method="post" data-sync-submit action="{{ route('admin.supplier-sync.connect', 'icorp') }}" class="mt-4 space-y-3">
                        @csrf <x-mode-version />
                        <x-ui.input name="phone" id="icorp-phone" type="tel" label="Телефон / логин iCorp" :value="$icorpLogin" autocomplete="username" required />
                        <x-ui.input name="password" id="icorp-password" type="password" label="Пароль iCorp" autocomplete="new-password" hint="Оставьте пустым, чтобы сохранить пароль для того же телефона." />
                        <x-ui.button>Сохранить и подключить</x-ui.button>
                    </form>
                @endif
                <div class="mt-4 flex flex-wrap gap-3">
                    <form method="post" data-sync-submit action="{{ route('admin.supplier-sync.refresh') }}">@csrf <x-mode-version /><input type="hidden" name="source" value="{{ $source }}"><x-ui.button variant="secondary">Обновить справочник</x-ui.button></form>
                    <form method="post" data-sync-submit action="{{ route('admin.supplier-sync.connect', $source) }}">@csrf <x-mode-version /><x-ui.button variant="secondary">{{ $status['status'] === 'unknown' ? 'Подключить' : 'Повторить подключение' }}</x-ui.button></form>
                </div>
                @if($source === 'icorp')
                    <details class="mt-4" @if(old('_sync_form') === 'takeover') open @endif><summary class="min-h-10 cursor-pointer py-2 font-semibold">Дополнительно: принудительный вход iCorp</summary>
                        <x-ui.alert type="warning">Принудительный вход завершит сеанс на другом устройстве. Используйте только после согласования с владельцем аккаунта.</x-ui.alert>
                        <form method="post" data-sync-submit action="{{ route('admin.supplier-sync.takeover') }}" class="mt-3 space-y-3">@csrf <x-mode-version /><input type="hidden" name="_sync_form" value="takeover"><x-ui.select name="confirmed" id="takeover-confirmed" :restore="old('_sync_form') === 'takeover'" label="Подтверждение выхода другого устройства" required><option value="">Выберите</option><option value="1">Подтверждаю принудительный вход</option></x-ui.select><x-ui.button variant="danger">Войти принудительно</x-ui.button></form>
                    </details>
                @endif
            </x-ui.card>
        @endforeach
    </div>
    <nav aria-label="Справочники поставщиков" class="flex flex-wrap gap-2">
        @foreach(['external' => 'Внешние поставщики', 'suppliers' => 'Наши поставщики'] as $key => $label)
            <x-ui.button :href="route('admin.supplier-sync.index', array_merge(request()->query(), ['tab' => $key]))" :variant="$tab === $key ? 'primary' : 'secondary'" :aria-current="$tab === $key ? 'page' : null">{{ $label }}</x-ui.button>
        @endforeach
    </nav>
    <x-ui.card>
        <form method="get" class="grid items-end gap-4 md:grid-cols-4">
            <input type="hidden" name="tab" value="{{ $tab }}">
            @if($tab === 'external')
                <input type="hidden" name="supplier_q" value="{{ request('supplier_q') }}"><input type="hidden" name="supplier_page" value="{{ request('supplier_page', 1) }}">
                <x-ui.input name="q" label="Поиск внешнего поставщика" :restore="false" :value="request('q')" />
                <x-ui.select name="service" label="Сервис" :restore="false"><option value="">Все сервисы</option>@foreach($serviceNames as $key => $label)<option value="{{ $key }}" @selected(request('service') === $key)>{{ $label }}</option>@endforeach</x-ui.select>
                <x-ui.select name="filter" label="Сопоставления" :restore="false">@foreach(['all' => 'Все', 'unmapped' => 'Без сопоставления', 'ambiguous' => 'Несколько кандидатов', 'suggested' => 'Есть предположения', 'confirmed' => 'Подтверждённые'] as $key => $label)<option value="{{ $key }}" @selected(request('filter', 'all') === $key)>{{ $label }}</option>@endforeach</x-ui.select>
            @else
                @foreach(['q', 'service', 'filter', 'page'] as $key)<input type="hidden" name="{{ $key }}" value="{{ request($key) }}">@endforeach
                <x-ui.input name="supplier_q" label="Поиск нашего поставщика" :restore="false" :value="request('supplier_q')" />
            @endif
            <x-ui.button>Найти</x-ui.button>
        </form>
    </x-ui.card>
    @if($tab === 'external')
        @if(str_starts_with((string) old('_sync_form'), 'approve-') && !$restoredReview)
            @include('admin.supplier-synchronizations.form-errors', ['restoring' => true])
        @endif
        <x-ui.card>
            <div class="table-wrap">
                <table class="data-table block w-full table-fixed lg:table lg:min-w-[1000px]">
                    <caption class="sr-only">Сопоставления поставщиков и выбранный источник</caption>
                    <thead class="hidden lg:table-header-group"><tr><th scope="col" class="w-1/5">iCorp</th><th scope="col" class="w-1/5">PriceList-TJ</th><th scope="col" class="w-1/5">Наш поставщик</th><th scope="col" class="w-1/5">Источник</th><th scope="col" class="w-1/5">Действия</th></tr></thead>
                    <tbody class="block lg:table-row-group">
            @forelse($partners as $partner)
                @php
                    $restoring = old('_sync_form') === 'mapping-'.$partner->id;
                    $value = fn ($key, $default = null) => $restoring ? old($key, $default) : $default;
                    $candidates = $suggestions[$partner->id];
                    $rowPartners = $partner->supplier_organization_id ? $mappedPartners->get($partner->supplier_organization_id, collect()) : collect([$partner]);
                    $rowConfiguration = $configurations->get($partner->supplier_organization_id);
                    $rowSuggestions = $reviewSuggestions[$partner->id];
                    $reviewRestoring = old('_sync_form') === 'approve-'.$partner->id;
                    $editing = $reviewRestoring || $rowPartners->contains(fn ($item) => old('_sync_form') === 'mapping-'.$item->id || (int) request('edit') === $item->id);
                @endphp
                <tr class="grid lg:table-row">
                    @foreach($serviceNames as $source => $serviceLabel)
                        @php($sourcePartner = $rowPartners->firstWhere('source', $source))
                        <td class="block align-top whitespace-normal lg:table-cell">
                            <p class="mb-1 text-xs font-semibold text-muted lg:hidden">{{ $serviceLabel }}</p>
                            @if($sourcePartner)<p class="break-words font-semibold">{{ $sourcePartner->name }}</p><p class="mt-1 break-words text-sm text-muted">{{ implode(', ', $sourcePartner->phones) ?: 'Телефоны не указаны' }}</p><p class="mt-1 text-sm text-muted">{{ $reviewSuggestions[$sourcePartner->id]['city_label'] }}</p>@elseif($rowSuggestions['external'][$source] !== [])
                                @include('admin.supplier-synchronizations.suggestion', ['candidate' => $rowSuggestions['external'][$source][0]])
                            @else<span class="text-muted">Не сопоставлен</span>@endif
                        </td>
                    @endforeach
                    <td class="block align-top whitespace-normal lg:table-cell"><p class="mb-1 text-xs font-semibold text-muted lg:hidden">Наш поставщик</p>@if($partner->supplier)<a class="inline-flex min-h-10 items-center break-words font-semibold underline" href="{{ route('admin.supplier-sync.show', $partner->supplier) }}">{{ $partner->supplier->name }}</a>@elseif($candidates !== []) @include('admin.supplier-synchronizations.suggestion', ['candidate' => $candidates[0]]) @else<span class="text-muted">Не выбран</span>@endif</td>
                    <td class="block align-top whitespace-normal lg:table-cell">
                        <p class="mb-1 text-xs font-semibold text-muted lg:hidden">Источник</p>
                        @if($rowConfiguration?->usesNewestAvailable())<p>Самый новый доступный прайс</p>@elseif($rowConfiguration?->selected_source)<p>{{ $rowConfiguration->selected_source === 'email' ? 'Почта' : $serviceNames[$rowConfiguration->selected_source] }}</p>@else<span class="text-muted">Не выбран</span>@endif
                        @if($partner->supplier)<a class="mt-2 inline-flex min-h-10 items-center font-semibold underline" href="{{ route('admin.supplier-import-profiles.edit', $partner->supplier) }}">Профиль импорта</a>@endif
                    </td>
                    <td class="block align-top whitespace-normal lg:table-cell">
                        <p class="mb-1 text-xs font-semibold text-muted lg:hidden">Действия</p>
                        <div class="flex flex-col items-start gap-2">
                            <x-ui.status-badge :status="$partner->supplier ? 'confirmed' : 'pending'" :label="$partner->supplier ? 'Подтверждено' : 'Без сопоставления'" />
                            @if($rowSuggestions['has'])
                                <x-ui.status-badge status="pending" :label="$rowSuggestions['ambiguous'] ? 'Несколько кандидатов' : 'Есть предположения'" />
                            @endif
                            <x-ui.button type="button" variant="secondary" data-dialog-open :aria-controls="'supplier-matching-'.$partner->id" aria-haspopup="dialog">{{ $partner->supplier ? 'Изменить сопоставление' : 'Сопоставить' }}</x-ui.button>
                            <x-ui.dialog :name="'supplier-matching-'.$partner->id" title="Сопоставление поставщика" :open-on-load="$editing">
                                <div class="min-h-0 overflow-y-auto px-5 pb-5">
                                    @if(!$partner->supplier || $rowSuggestions['has'] || $reviewRestoring)
                                        @include('admin.supplier-synchronizations.approval-form')
                                    @endif
                                    <details id="mapping-{{ $partner->id }}" @if($editing) open @endif>
                                        <summary class="min-h-10 cursor-pointer py-2 font-semibold">{{ $partner->supplier ? 'Изменить сохранённое сопоставление' : 'Дополнительно: ручное сопоставление' }} · {{ $serviceNames[$partner->source] }}</summary>
                                        @foreach($rowPartners as $editablePartner)
                                            @include('admin.supplier-synchronizations.mapping-form', [
                                                'partner' => $editablePartner,
                                                'candidates' => $suggestions[$editablePartner->id],
                                                'restoring' => old('_sync_form') === 'mapping-'.$editablePartner->id,
                                                'value' => fn ($key, $default = null) => old('_sync_form') === 'mapping-'.$editablePartner->id ? old($key, $default) : $default,
                                            ])
                                        @endforeach
                                    </details>
                                </div>
                            </x-ui.dialog>
                            @if($partner->supplier)
                                <details @if($rowPartners->contains(fn ($item) => old('_sync_form') === 'unmap-'.$item->id)) open @endif>
                                    <summary class="min-h-10 cursor-pointer py-2 font-semibold text-danger">Удалить</summary>
                                    <p class="my-2 text-sm text-muted">Удаляется только связь с выбранным сервисом.</p>
                                    @foreach($rowPartners as $mappedPartner)
                                        @php($unmapRestoring = old('_sync_form') === 'unmap-'.$mappedPartner->id)
                                        <form method="post" data-sync-submit action="{{ route('admin.supplier-sync.unmap', $mappedPartner) }}" class="mb-4 space-y-3">@csrf @method('DELETE') <x-mode-version />
                                            <input type="hidden" name="_sync_form" value="unmap-{{ $mappedPartner->id }}">
                                            <input type="hidden" name="expected_supplier_organization_id" value="{{ $mappedPartner->supplier_organization_id }}">
                                            @include('admin.supplier-synchronizations.form-errors', ['restoring' => $unmapRestoring])
                                            <x-ui.select name="confirmed" :id="'unmap-'.$mappedPartner->id.'-confirmed'" :restore="$unmapRestoring" :label="'Удалить связь с '.$serviceNames[$mappedPartner->source]" required><option value="">Выберите</option><option value="1">Подтверждаю удаление связи</option></x-ui.select>
                                            <x-ui.button variant="danger">Удалить {{ $serviceNames[$mappedPartner->source] }}</x-ui.button>
                                        </form>
                                    @endforeach
                                </details>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr class="block lg:table-row"><td colspan="5" class="block whitespace-normal lg:table-cell">
                @if($directoryCounts->sum() === 0 || (request()->filled('service') && !$directoryCounts->get(request('service'), 0) && !request()->filled('q') && in_array(request('filter', 'all'), ['', 'all'], true)))
                    <x-ui.empty-state title="Справочники ещё не загружены" description="Запросите обновление справочника нужного сервиса. Задание будет поставлено в очередь."><x-slot:action><div class="flex flex-wrap gap-3">@foreach($serviceNames as $source => $label)<form method="post" data-sync-submit action="{{ route('admin.supplier-sync.refresh') }}">@csrf <x-mode-version /><input type="hidden" name="source" value="{{ $source }}"><x-ui.button variant="secondary">Загрузить {{ $label }}</x-ui.button></form>@endforeach</div></x-slot:action></x-ui.empty-state>
                @else
                    <x-ui.empty-state title="Ничего не найдено" description="Измените запрос или сбросьте фильтры."><x-slot:action><x-ui.button variant="secondary" :href="route('admin.supplier-sync.index', request()->except(['q', 'filter', 'service', 'page']))">Сбросить фильтры</x-ui.button></x-slot:action></x-ui.empty-state>
                @endif
                </td></tr>
            @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
        {{ $partners->links() }}
    @else
        <x-ui.card><h2 class="mb-3 font-bold">Наши поставщики · {{ $suppliers->total() }}</h2><div class="divide-y divide-border">@forelse($suppliers as $supplier)<div class="flex flex-wrap items-center justify-between gap-3 py-3"><p class="min-w-0 flex-1 break-words font-semibold">{{ $supplier->name }}</p><x-ui.button variant="secondary" :href="route('admin.supplier-sync.show', $supplier)">Открыть</x-ui.button></div>@empty<x-ui.empty-state title="Наши поставщики не найдены" description="Попробуйте другое название."><x-slot:action><x-ui.button variant="secondary" :href="route('admin.supplier-sync.index', array_merge(request()->except(['supplier_q', 'supplier_page']), ['tab' => 'suppliers']))">Сбросить поиск</x-ui.button></x-slot:action></x-ui.empty-state>@endforelse</div>{{ $suppliers->links() }}</x-ui.card>
    @endif
</div>
@endsection
