@extends('layouts.app')
@section('content')
@php
    $labels = ['manual' => 'Ручной запуск', 'automatic' => 'По расписанию', 'mailbox' => 'Поступление почты', 'queued' => 'В очереди', 'fetching' => 'Загрузка', 'processing' => 'Обработка', 'activating' => 'Активация', 'activated' => 'Активирован', 'completed' => 'Завершён', 'validated' => 'Проверен', 'review' => 'Ожидает проверки', 'skipped' => 'Пропущен', 'failed' => 'Ошибка', 'unchanged' => 'Без изменений', 'waiting_mailbox' => 'Ожидает почту', 'waiting_import' => 'Ожидает импорт', 'preferred_fresh' => 'Приоритетный источник актуален', 'preferred_unresolved' => 'Ожидает приоритетный источник', 'unchanged_content' => 'Содержимое не изменилось', 'validation_failed' => 'Не пройдены пороги проверки', 'review_missing_date' => 'Требуется подтверждение даты', 'review_date_precision' => 'Недостаточная точность даты', 'review_future_date' => 'Дата в будущем', 'review_active_date_unknown' => 'Неизвестна дата активного прайса', 'review_pending' => 'Ожидает проверки предпросмотра', 'stale' => 'Устарел', 'older_than_active' => 'Старше активного прайса', 'equal_date_lower_priority' => 'Приоритет ниже при равной дате', 'configuration_missing' => 'Настройки синхронизации отсутствуют', 'configuration_changed' => 'Настройки изменились', 'profile_changed' => 'Профиль изменился', 'mapping_changed' => 'Сопоставление изменилось', 'paused' => 'Автоматическая загрузка приостановлена', 'source_not_selected' => 'Источник исключён: выбран другой', 'selection_required' => 'Выберите источник в профиле импорта', 'source_disabled' => 'Источник выключен', 'inactive_supplier' => 'Поставщик неактивен', 'no_new_mailbox_list' => 'Нет нового прайса в почте', 'unmapped' => 'Нет сопоставления', 'missing_profile' => 'Нет активного профиля', 'empty_catalog' => 'Пустой прайс', 'import_failed' => 'Ошибка импорта', 'no_eligible_candidate' => 'Нет подходящего прайса', 'service_busy' => 'Сервис занят', 'authentication' => 'Требуется авторизация', 'permission' => 'Нет доступа', 'transport' => 'Ошибка соединения', 'extraction' => 'Ошибка чтения данных', 'takeover_required' => 'Требуется отдельный принудительный вход'];
    $labels += ['mailbox_disabled' => 'Проверка почты выключена', 'availability_pending' => 'Ожидает проверку справочника', 'waiting_availability' => 'Ожидает доступность источников', 'service_retry_pending' => 'Ожидает повторную проверку сервиса', 'download_busy' => 'Слот загрузки занят', 'pacing_wait' => 'Ожидает разрешённое время запроса', 'downloading' => 'Загрузка страниц', 'retry' => 'Ожидает повторной попытки', 'cooldown' => 'Пауза после ошибок', 'cancelled' => 'Отменён', 'challenge' => 'Требуется проверка доступа', 'rate_limited' => 'Ограничение частоты запросов', 'service_cooldown' => 'Пауза сервиса', 'retry_exhausted' => 'Повторы исчерпаны', 'mailbox_processing' => 'Ожидает обработку почты', 'mailbox_unhealthy' => 'Проверка почты не завершена', 'mailbox_failed' => 'Ошибка проверки почты', 'newest_publication' => 'Выбрана самая новая публикация', 'newer_candidate_pending' => 'Ожидает более новый прайс', 'same_source_equal_date' => 'Дата источника не изменилась'];
    $sourceNames = ['email' => 'Почта', 'icorp' => 'iCorp', 'pricelist_tj' => 'PriceList-TJ'];
    $precisionLabels = ['day' => 'До дня', 'minute' => 'До минуты', 'second' => 'До секунды'];
    $formatDate = fn ($date) => $date?->timezone('Asia/Dushanbe')->format('d.m.Y H:i') ?? 'Нет данных';
@endphp
<x-ui.page-header :title="$supplier->name" description="Синхронизация прайс-листов · время Душанбе">
    <x-slot:actions><x-ui.button variant="secondary" :href="route('admin.supplier-sync.index')">Справочник поставщиков</x-ui.button></x-slot:actions>
</x-ui.page-header>
<div class="space-y-6">
    <x-ui.card>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <x-ui.status-badge :status="$configuration->automatic_enabled && !$configuration->paused ? 'confirmed' : 'pending'" :label="!$configuration->settings_confirmed ? 'Требуется настройка' : ($configuration->paused ? 'На паузе' : ($configuration->automatic_enabled ? 'Автоматическая загрузка включена' : 'Автоматическая загрузка выключена'))" />
            <div class="flex flex-wrap gap-3">
                @if($configuration->exists)<form data-sync-submit method="post" action="{{ route('admin.supplier-sync.fetch', $supplier) }}">@csrf <x-mode-version /><x-ui.button>Загрузить сейчас</x-ui.button></form>@else<x-ui.button :href="route('admin.supplier-import-profiles.edit', $supplier)">Настроить загрузку</x-ui.button>@endif
                <x-ui.button variant="secondary" :href="request()->fullUrl()">Обновить статус</x-ui.button>
            </div>
        </div>
        <div class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><p>Последняя успешная активация<br><strong>{{ $formatDate($configuration->last_activated_at) }}</strong></p><p>Следующая проверка<br><strong>{{ $configuration->next_check_at ? $formatDate($configuration->next_check_at) : 'Не назначена' }}</strong></p></div>
        <p class="mt-3 font-semibold">Выбранный источник: {{ $sourceNames[$configuration->selected_source] ?? 'Не выбран' }}</p>
        <x-ui.button class="mt-3" variant="secondary" :href="route('admin.supplier-import-profiles.edit', $supplier)">Профиль импорта</x-ui.button>
        <p class="mt-3 text-sm text-muted">Ручная загрузка ставится в очередь и доступна при паузе. Проверки дат и выбранного источника обязательны. Для результата нажмите «Обновить статус».</p>
    </x-ui.card>
    <nav class="flex flex-wrap gap-2" aria-label="Разделы синхронизации">
        @foreach(['overview' => 'Обзор', 'review' => 'Требуют проверки', 'history' => 'История'] as $key => $label)
            <x-ui.button :href="route('admin.supplier-sync.show', [$supplier, 'tab' => $key])" :variant="$tab === $key ? 'primary' : 'secondary'" :aria-current="$tab === $key ? 'page' : null">{{ $label }}{{ $key === 'review' && $previews->count() ? ' · '.$previews->count() : '' }}</x-ui.button>
        @endforeach
    </nav>
    @if($configuration->usesNewestAvailable() || $downloads->isNotEmpty())
        @include('admin.supplier-synchronizations.background-health')
    @endif
    @if($tab === 'overview')
        <div class="grid gap-4 lg:grid-cols-2">
            <x-ui.card><h2 class="mb-3 font-bold">Активный каталог</h2>
                @if($active = $supplier->activePriceListImport)
                    @if($configuration->usesNewestAvailable() && (!$active->source_published_at || $active->source_published_at->lt(now()->subHours($configuration->freshness_hours))))<x-ui.alert type="warning">Активный прайс устарел или дата публикации неизвестна.</x-ui.alert>@endif
                    <p class="break-words">{{ $active->original_filename }} · {{ $active->valid_rows }} корректных строк</p><p class="mt-2 text-sm text-muted">Публикация: {{ $formatDate($active->source_published_at) }} · {{ $precisionLabels[$active->source_date_precision] ?? 'Точность не определена' }}</p><x-ui.button class="mt-3" variant="secondary" :href="route('price-list-imports.show', $active)">Просмотреть каталог</x-ui.button>
                @else<p class="text-muted">Активного каталога пока нет.</p>@endif
            </x-ui.card>
            <x-ui.card><h2 class="mb-3 font-bold">Последний запуск</h2>
                @if($latest = $runs->first())<x-ui.status-badge :status="$latest->status === 'completed' ? 'confirmed' : $latest->status" :label="$labels[$latest->status] ?? 'Требуется проверка'" /><p class="mt-2">{{ $labels[$latest->reason] ?? 'Результат ещё не получен' }}</p><p class="mt-2 text-sm text-muted">{{ $formatDate($latest->created_at) }} · {{ $sourceNames[$latest->selected_source] ?? 'Источник ещё не выбран' }}</p>@else<p class="text-muted">Запусков пока нет.</p>@endif
            </x-ui.card>
        </div>
        <x-ui.card><h2 class="mb-3 font-bold">Проверка готовности</h2><ul class="space-y-3">
            @foreach($sources as $source => $settings)
                @php
                    $partner = $partners->firstWhere('source', $source);
                    $ready = $source === 'email' ? (bool) $supplier->importProfile?->is_active : (bool) ($partner?->profile_active && $partner?->profile_configuration);
                @endphp
                <li class="flex flex-wrap items-center gap-3"><x-ui.status-badge :status="$configuration->selected_source !== $source || $ready ? 'confirmed' : 'pending'" :label="$ready ? 'Готов' : 'Нужна настройка'" /><span>{{ $sourceNames[$source] }}</span>
                    @if($configuration->selected_source === $source && !$ready)<a class="inline-flex min-h-10 items-center font-semibold underline" href="{{ $source !== 'email' && !$partner ? route('admin.supplier-sync.index', ['service' => $source, 'filter' => 'unmapped']) : route('admin.supplier-import-profiles.edit', $supplier) }}">{{ $source !== 'email' && !$partner ? 'Подтвердить сопоставление' : 'Настроить профиль' }}</a>@endif
                </li>
            @endforeach
            <li><x-ui.status-badge :status="$configuration->settings_confirmed ? 'confirmed' : 'pending'" :label="$configuration->settings_confirmed ? 'Настройки сохранены' : 'Настройки не сохранены'" /> <a class="inline-flex min-h-10 items-center font-semibold underline" href="{{ route('admin.supplier-import-profiles.edit', $supplier) }}">Проверить настройки</a></li>
        </ul></x-ui.card>
        <x-ui.card><h2 class="mb-3 font-bold">Пять последних запусков</h2>@include('admin.supplier-synchronizations.history', ['historyRuns' => $runs->take(5), 'showPagination' => false])<x-ui.button class="mt-4" variant="secondary" :href="route('admin.supplier-sync.show', [$supplier, 'tab' => 'history'])">История синхронизации</x-ui.button></x-ui.card>
    @elseif($tab === 'review')
        @include('admin.supplier-synchronizations.review')
    @else
        <x-ui.card><h2 class="mb-3 font-bold">История синхронизации</h2>@include('admin.supplier-synchronizations.history', ['historyRuns' => $runs, 'showPagination' => true])</x-ui.card>
    @endif
</div>
@endsection
