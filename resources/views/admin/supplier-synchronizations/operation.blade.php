<x-ui.card data-import-progress data-status-url="{{ route('admin.supplier-sync.show', $supplier) }}" data-watch-new="1">
    <h2 class="font-bold">Ход синхронизации</h2>
    <p class="mt-2 text-sm text-muted">В очереди → Проверка источников → Загрузка → Обработка → Публикация → Завершено</p>
    <div class="mt-3 grid gap-3 text-sm sm:grid-cols-2" data-supplier-summary aria-live="polite">
        @foreach(['label' => 'Состояние', 'active_source' => 'Активный источник', 'publication' => 'Публикация прайс-листа', 'last_check' => 'Последняя завершённая проверка', 'next_check' => 'Следующая проверка / повтор', 'newer_data' => 'Более новые данные', 'activated_at' => 'Последняя успешная публикация', 'recovery' => 'Восстановление'] as $key => $label)
            <p>{{ $label }}: <strong data-supplier-field="{{ $key }}">{{ $supplierStatus[$key] ?? 'Нет данных' }}</strong></p>
        @endforeach
    </div>
    @if($supplierStatus['recovery'])<p class="mt-3 text-warning">{{ $supplierStatus['recovery'] }}</p>@endif
    <div class="mt-4 space-y-3" data-progress-entries aria-live="polite">
        @foreach($supplierStatus['entries'] as $entry)<div><p class="font-semibold">{{ $entry['title'] }}</p><p>{{ $entry['label'] }}</p><p class="text-sm text-muted">{{ $entry['detail'] }}</p>@if($entry['url'])<a class="inline-flex min-h-10 items-center font-semibold underline" href="{{ $entry['url'] }}">{{ $entry['action_label'] ?? 'Открыть подробности' }}</a>@endif</div>@endforeach
    </div>
    <p class="mt-3 text-sm text-muted" data-progress-feedback role="status">Статус обновляется автоматически каждые три секунды.</p>
</x-ui.card>
