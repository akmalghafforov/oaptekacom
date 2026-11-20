@extends('layouts.app')
@section('content')
    <x-ui.page-header title="Прайс-листы" description="Автоматическая обработка и публикация предложений поставщика." />

    @if(auth()->user()->isAdminMode())
        <x-ui.card class="mt-6"><h2 class="font-bold">Новый поставщик</h2><form method="post" action="{{ route('admin.suppliers.store') }}" class="mt-4 grid gap-4 md:grid-cols-4 md:items-end">@csrf <x-mode-version /><x-ui.input name="name" label="Название" required /><x-ui.input name="city" label="Город" /><x-ui.input name="phone" label="Телефон" /><x-ui.button>Создать</x-ui.button></form></x-ui.card>
        <x-ui.card class="mt-4">
            <h2 class="font-bold">Профили поставщиков</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($suppliers as $supplier)
                    @php
                        $hasActiveImportProfile = $supplier->manualImportProfiles->isNotEmpty();
                    @endphp
                    <a @class([
                        'flex min-h-10 flex-col items-start justify-center gap-0.5 rounded-control border px-3 py-1.5 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2',
                        'border-success-border bg-success-surface text-success-strong hover:border-success-control-border hover:bg-success-surface-strong focus-visible:outline-success-focus' => $hasActiveImportProfile,
                        'border-danger-border bg-danger-surface text-danger hover:border-danger-control-border hover:bg-danger-surface-strong focus-visible:outline-danger-focus' => ! $hasActiveImportProfile,
                    ]) href="{{ route('admin.supplier-import-profiles.index', $supplier) }}">
                        <span>{{ $supplier->name }}</span>
                        <span class="text-xs font-medium">{{ $hasActiveImportProfile ? 'Активный профиль' : 'Нет активного профиля' }}</span>
                    </a>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    @php
        $admin = auth()->user()->isAdminMode();
        $selectedSupplier = $admin ? old('supplier_organization_id') : $suppliers->first()?->id;
        $selectedSupplier = is_scalar($selectedSupplier) ? $selectedSupplier : null;
        $options = $uploadOptions[$selectedSupplier] ?? ['profiles' => collect(), 'default' => null];
        $selectedProfile = old('supplier_import_profile_id', $options['default']);
        $selectedProfile = is_scalar($selectedProfile) ? $selectedProfile : null;
        $canUpload = $options['profiles']->isNotEmpty();
    @endphp
    <x-ui.card class="mt-6">
        <form id="price-list-upload" method="post" action="{{ route('price-list-imports.store') }}" enctype="multipart/form-data" class="grid gap-4 md:grid-cols-3 md:items-end">
            @csrf <x-mode-version />
            @if(auth()->user()->isAdminMode())
                <x-ui.select name="supplier_organization_id" label="Поставщик" required>
                    <option value="">Выберите поставщика</option>
                    @foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) $selectedSupplier === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach
                </x-ui.select>
            @endif
            <x-ui.select name="supplier_import_profile_id" label="Профиль ручного импорта" required :disabled="! $canUpload">
                <option value="">Выберите профиль</option>
                @foreach($options['profiles'] as $option)
                    <option value="{{ $option['id'] }}" @selected((string) $selectedProfile === (string) $option['id'])>{{ $option['name'] }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input name="file" type="file" label="Файл CSV, TSV, XLS или XLSX" accept=".csv,.tsv,.xls,.xlsx" required :disabled="! $canUpload" />
            <x-ui.button :disabled="! $canUpload">Загрузить</x-ui.button>
            <p data-upload-status class="text-sm text-muted md:col-span-full" role="status" @if($canUpload) hidden @endif>{{ $selectedSupplier ? 'У поставщика нет доступных профилей ручного импорта.' : 'Выберите поставщика для загрузки.' }}</p>
        </form>
    </x-ui.card>

    @if($admin)
        <script>
            const uploadForm = document.getElementById('price-list-upload');
            const supplierSelect = uploadForm.elements.supplier_organization_id;
            const profileSelect = uploadForm.elements.supplier_import_profile_id;
            const uploadOptions = {{ Illuminate\Support\Js::from($uploadOptions) }};
            supplierSelect.addEventListener('change', () => {
                const options = uploadOptions[supplierSelect.value];
                const enabled = Boolean(options?.profiles.length);
                profileSelect.replaceChildren(new Option('Выберите профиль', ''));
                for (const profile of options?.profiles ?? []) {
                    profileSelect.add(new Option(profile.name, profile.id));
                }
                profileSelect.value = options?.default ?? '';
                profileSelect.disabled = uploadForm.elements.file.disabled = uploadForm.querySelector('button[type="submit"]').disabled = !enabled;
                uploadForm.elements.file.value = '';
                const status = uploadForm.querySelector('[data-upload-status]');
                status.hidden = enabled;
                status.textContent = supplierSelect.value ? 'У поставщика нет доступных профилей ручного импорта.' : 'Выберите поставщика для загрузки.';
            });
        </script>
    @endif

    <div class="mt-6 table-wrap">
        <table class="data-table">
            <thead><tr><th>Файл</th><th>Поставщик</th><th>Статус</th><th>Строки</th><th>Дата</th><th></th></tr></thead>
            <tbody>
                @forelse($imports as $import)
                    <tr><td>{{ $import->original_filename }}</td><td>{{ $import->supplier->name }}</td><td><x-ui.status-badge :status="$import->status->value" :label="$import->status->label()" /></td><td>{{ $import->valid_rows }} / {{ $import->error_rows }} ош.</td><td>{{ $import->created_at->format('d.m.Y H:i') }}</td><td class="text-right"><a class="font-semibold text-accent hover:underline" href="{{ route('price-list-imports.show', $import) }}">Открыть</a></td></tr>
                @empty
                    <tr><td colspan="6"><x-ui.empty-state title="Прайс-листов пока нет" description="Загруженные файлы появятся здесь." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $imports->links('components.ui.pagination') }}</div>
@endsection
