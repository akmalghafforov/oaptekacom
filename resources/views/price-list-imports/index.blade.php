@extends('layouts.app')
@section('content')
    <x-ui.page-header title="Прайс-листы" description="Автоматическая обработка и публикация предложений поставщика." />

    @if(auth()->user()->isAdmin())
        <x-ui.card class="mt-6"><h2 class="font-bold">Новый поставщик</h2><form method="post" action="{{ route('admin.suppliers.store') }}" class="mt-4 grid gap-4 md:grid-cols-4 md:items-end">@csrf<x-ui.input name="name" label="Название" required /><x-ui.input name="city" label="Город" /><x-ui.input name="phone" label="Телефон" /><x-ui.button>Создать</x-ui.button></form></x-ui.card>
        <x-ui.card class="mt-4">
            <h2 class="font-bold">Профили поставщиков</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($suppliers as $supplier)
                    @php($hasActiveImportProfile = $supplier->importProfile?->is_active)
                    <a @class([
                        'flex min-h-10 flex-col items-start justify-center gap-0.5 rounded-control border px-3 py-1.5 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2',
                        'border-emerald-200 bg-emerald-50 text-emerald-800 hover:border-emerald-300 hover:bg-emerald-100 focus-visible:outline-emerald-600' => $hasActiveImportProfile,
                        'border-red-200 bg-red-50 text-danger hover:border-red-300 hover:bg-red-100 focus-visible:outline-red-600' => ! $hasActiveImportProfile,
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
            @csrf
            @if(auth()->user()->isAdmin())
                <x-ui.select name="supplier_organization_id" label="Поставщик" required>
                    <option value="">Выберите поставщика</option>
                    @foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach
                </x-ui.select>
            @endif
            <x-ui.input name="file" type="file" label="Файл CSV, XLS или XLSX" accept=".csv,.xls,.xlsx" required />
            <x-ui.button>Загрузить</x-ui.button>
        </form>
    </x-ui.card>

    <div class="mt-6 table-wrap">
        <table class="data-table">
            <thead><tr><th>Файл</th><th>Поставщик</th><th>Статус</th><th>Строки</th><th>Дата</th><th></th></tr></thead>
            <tbody>
                @forelse($imports as $import)
                    <tr><td>{{ $import->original_filename }}</td><td>{{ $import->supplier->name }}</td><td><x-ui.status-badge :status="$import->status->value" :label="$import->status->label()" /></td><td>{{ $import->valid_rows }} / {{ $import->error_rows }} ош.</td><td>{{ $import->created_at->format('d.m.Y H:i') }}</td><td class="text-right"><a class="font-semibold text-brand-700 hover:underline" href="{{ route('price-list-imports.show', $import) }}">Открыть</a></td></tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty-state title="Прайс-листов пока нет" description="Загруженные файлы появятся здесь." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $imports->links() }}</div>
@endsection
