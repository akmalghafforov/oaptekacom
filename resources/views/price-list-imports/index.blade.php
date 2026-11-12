@extends('layouts.app')
@section('content')
    <x-ui.page-header title="Прайс-листы" description="Автоматическая обработка и публикация предложений поставщика." />

    @if(auth()->user()->isAdminMode())
        <x-ui.card class="mt-6"><h2 class="font-bold">Новый поставщик</h2><form method="post" action="{{ route('admin.suppliers.store') }}" class="mt-4 grid gap-4 md:grid-cols-4 md:items-end">@csrf <x-mode-version /><x-ui.input name="name" label="Название" required /><x-ui.input name="city" label="Город" /><x-ui.input name="phone" label="Телефон" /><x-ui.button>Создать</x-ui.button></form></x-ui.card>
        <x-ui.card class="mt-4">
            <h2 class="font-bold">Профили поставщиков</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($suppliers as $supplier)
                    @php($hasActiveImportProfile = $supplier->importProfile?->is_active)
                    <a @class([
                        'flex min-h-10 flex-col items-start justify-center gap-0.5 rounded-control border px-3 py-1.5 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2',
                        'border-success-border bg-success-surface text-success-strong hover:border-success-control-border hover:bg-success-surface-strong focus-visible:outline-success-focus' => $hasActiveImportProfile,
                        'border-danger-border bg-danger-surface text-danger hover:border-danger-control-border hover:bg-danger-surface-strong focus-visible:outline-danger-focus' => ! $hasActiveImportProfile,
                    ]) href="{{ route('admin.supplier-import-profiles.edit', $supplier) }}">
                        <span>{{ $supplier->name }}</span>
                        <span class="text-xs font-medium">{{ $hasActiveImportProfile ? 'Активный профиль' : 'Нет активного профиля' }}</span>
                    </a>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    <x-ui.card class="mt-6">
        <form method="post" action="{{ route('price-list-imports.store') }}" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-3 md:items-end">
            @csrf <x-mode-version />
            @if(auth()->user()->isAdminMode())
                <x-ui.select name="supplier_organization_id" label="Поставщик" required>
                    <option value="">Выберите поставщика</option>
                    @foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach
                </x-ui.select>
            @endif
            <x-ui.input name="file" type="file" label="Файл CSV, XLS или XLSX" accept=".csv,.xls,.xlsx" required />
            <x-ui.button>Загрузить</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.card class="mt-6" data-import-progress data-progress-layout="table" data-status-url="{{ route('price-list-imports.index', ['tab' => $tab, 'page' => $history->currentPage()]) }}" data-watch-new="1" data-show-source="{{ $tab === 'services' ? '1' : '0' }}">
        <h2 class="font-bold">История импорта</h2>
        <nav class="mt-4 flex flex-wrap gap-2" aria-label="Источники импорта">
            @foreach(['files' => 'Почта и файлы', 'services' => 'iCorp и PriceList.tj'] as $key => $label)
                <x-ui.button :href="route('price-list-imports.index', ['tab' => $key])" :variant="$tab === $key ? 'primary' : 'secondary'" :aria-current="$tab === $key ? 'page' : null">{{ $label }}</x-ui.button>
            @endforeach
        </nav>
        <div class="mt-4 table-wrap" tabindex="0" aria-label="История импорта: таблица с горизонтальной прокруткой">
            <table class="data-table">
                <thead><tr><th>{{ $tab === 'services' ? 'Файл / источник' : 'Файл' }}</th><th>Поставщик</th><th>Статус</th><th>Строки</th><th>Дата</th><th><span class="sr-only">Подробности</span></th></tr></thead>
                <tbody data-progress-entries aria-live="polite">
                    @forelse($history as $entry)
                        <tr>
                            <td><p class="min-w-48 max-w-sm break-words [overflow-wrap:anywhere]">{{ $entry['filename'] ?? $entry['source_label'] }}</p>@if($tab === 'services')<p class="mt-1 text-xs text-muted">{{ $entry['filename'] ? $entry['source_label'] : 'Файл ещё не получен' }}</p>@endif</td>
                            <td>{{ $entry['supplier'] }}</td>
                            <td><x-ui.status-badge :status="$entry['status']" :label="$entry['label']" />@if($tab === 'services')<p class="mt-1 min-w-48 max-w-sm text-sm text-muted">{{ $entry['detail'] }}</p>@endif</td>
                            <td class="whitespace-nowrap">{{ $entry['rows'] ?? '—' }}</td>
                            <td class="whitespace-nowrap">{{ $entry['date'] }}</td>
                            <td class="text-right">@if($entry['url'])<a class="inline-flex min-h-10 items-center font-semibold text-accent hover:underline" href="{{ $entry['url'] }}">Открыть</a>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-ui.empty-state :title="$tab === 'services' ? 'Загрузок из сервисов пока нет' : 'Прайс-листов пока нет'" :description="$tab === 'services' ? 'Загрузки из iCorp и PriceList.tj появятся здесь после запуска.' : 'Загруженные файлы появятся здесь.'" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $history->links('components.ui.pagination') }}</div>
        <p class="mt-3 text-sm text-muted" data-progress-feedback role="status">Статус обновляется автоматически.</p>
        <x-ui.button variant="secondary" class="mt-3" :href="request()->fullUrl()">Обновить статус</x-ui.button>
    </x-ui.card>
@endsection
