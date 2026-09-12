@extends('layouts.app')
@section('content')
@php
    $attributeLabels = ['name' => 'Название товара', 'sku' => 'SKU', 'price' => 'Цена', 'expiration' => 'Срок годности', 'manufacturer' => 'Производитель', 'country' => 'Страна', 'batch' => 'Серия', 'unit' => 'Единица', 'quantity' => 'Количество', 'total' => 'Сумма'];
    $savedMapping = array_flip($profile->configuration['mapping'] ?? []);
    $selectedDataRow = (int) old('data_row', $profile->configuration['data_row'] ?? 1);
@endphp
<x-ui.page-header :title="'Профиль импорта: '.$organization->name" description="Загрузите образец, затем укажите назначение столбцов и первую строку товаров." />
<x-ui.card class="mt-6">
    <h2 class="text-lg font-bold">Образец прайс-листа</h2>
    <p class="mt-1 text-sm text-muted">CSV, XLS или XLSX. Файл хранится приватно и используется только для настройки.</p>
    @if($profile->sample_metadata)<p class="mt-3 text-sm"><span class="font-semibold">Текущий файл:</span> {{ $profile->sample_metadata['filename'] }} ({{ number_format($profile->sample_metadata['size'] / 1024, 1, ',', ' ') }} КБ)</p>@endif
    <form method="post" action="{{ route('admin.supplier-import-profiles.sample.store', $organization) }}" enctype="multipart/form-data" class="mt-4 flex flex-wrap items-end gap-3">
        @csrf
        <x-ui.input name="sample" type="file" label="Файл-образец" accept=".csv,.xls,.xlsx" required />
        <x-ui.button>{{ $profile->sample_metadata ? 'Заменить образец' : 'Загрузить образец' }}</x-ui.button>
    </form>
</x-ui.card>
@if($preview)
<form id="profile-form" method="post" action="{{ route('admin.supplier-import-profiles.update', $organization) }}" class="mt-6 space-y-6" novalidate>
    @csrf @method('PUT')
    <x-ui.card>
        <div class="grid gap-4 md:grid-cols-3">
            <x-ui.input name="name" label="Название профиля" :value="$profile->name" required />
            <x-ui.select name="file_type" label="Тип импортируемого файла">@foreach(['xlsx' => 'XLSX', 'xls' => 'XLS', 'csv' => 'CSV'] as $value => $label)<option value="{{ $value }}" @selected(old('file_type', $profile->file_type?->value ?? $profile->file_type) === $value)>{{ $label }}</option>@endforeach</x-ui.select>
            <x-ui.select name="worksheet" label="Лист" data-worksheet data-preview-url="{{ route('admin.supplier-import-profiles.sample.preview', $organization) }}">@foreach($preview['worksheets'] as $worksheet)<option value="{{ $worksheet }}" @selected(old('worksheet', $preview['worksheet']) === $worksheet)>{{ $worksheet }}</option>@endforeach</x-ui.select>
        </div>
        <div class="mt-4"><x-ui.textarea name="sender_emails" label="Email-адреса отправителей" :value="$senderEmails" hint="Один адрес на строку. Адрес может быть назначен только одному поставщику." /></div>
        <div class="mt-4 grid gap-4 md:grid-cols-3">
            <x-ui.input name="decimal_separator" label="Десятичный разделитель" :value="$profile->configuration['decimal_separator'] ?? '.'" required />
            <x-ui.select name="matching_strategy" label="Сопоставление" data-matching-strategy>@foreach(['name' => 'По названию', 'sku' => 'Строго по SKU', 'sku_then_name' => 'SKU, затем название'] as $value => $label)<option value="{{ $value }}" @selected(old('matching_strategy', $profile->configuration['matching_strategy'] ?? 'name') === $value)>{{ $label }}</option>@endforeach</x-ui.select>
            <x-ui.select name="activation_mode" label="Активация"><option value="manual" @selected(old('activation_mode', $profile->configuration['activation_mode'] ?? 'manual') === 'manual')>Вручную</option><option value="automatic" @selected(old('activation_mode', $profile->configuration['activation_mode'] ?? 'manual') === 'automatic')>Автоматически</option></x-ui.select>
        </div>
    </x-ui.card>
    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 class="text-lg font-bold">Сопоставление столбцов</h2><p class="mt-1 text-sm text-muted">Выберите назначение над каждым столбцом и строку, с которой начинаются товары.</p></div><p class="text-sm text-muted" data-dimensions>{{ $preview['highest_row'] }} строк, {{ $preview['highest_column'] }} столбцов</p></div>
        <x-ui.alert type="danger" class="mt-4 hidden" data-mapping-error></x-ui.alert>
        <x-ui.alert type="warning" class="mt-4 {{ $preview['truncated'] ? '' : 'hidden' }}" data-truncation>Показаны первые 100 строк из {{ $preview['highest_row'] }}. Начальную строку можно выбрать только в этом диапазоне.</x-ui.alert>
        <div class="table-wrap mt-4"><table class="data-table" data-mapping-table>
            <thead><tr><th class="sticky left-0 z-10 min-w-44">Начало импорта</th>@foreach($preview['columns'] as $column)<th class="min-w-52"><span class="mb-2 block text-center text-xs" data-column-label="{{ $column }}">Столбец {{ $column }}</span><x-ui.select name="column_mappings[{{ $column }}]" label="Назначение столбца {{ $column }}" class="min-w-44" data-column-mapping data-column="{{ $column }}"><option value="ignore">Игнорировать</option>@foreach($attributeLabels as $field => $label)<option value="{{ $field }}" @selected(old('column_mappings.'.$column, $savedMapping[$column] ?? 'ignore') === $field)>{{ $label }}</option>@endforeach</x-ui.select></th>@endforeach</tr></thead>
            <tbody>@foreach($preview['rows'] as $row)<tr data-source-row="{{ $row['number'] }}"><td class="sticky left-0 bg-inherit"><x-ui.row-start-radio :row="$row['number']" :checked="$selectedDataRow === $row['number']" /><span class="mt-1 block text-xs font-semibold" data-row-state></span></td>@foreach($preview['columns'] as $column)<td>{{ $row['cells'][$column] }}</td>@endforeach</tr>@endforeach</tbody>
        </table></div>
        <div class="mt-5 flex justify-end"><x-ui.button data-save-button>Сохранить и активировать профиль</x-ui.button></div>
    </x-ui.card>
</form>
<template data-mapping-options><option value="ignore">Игнорировать</option>@foreach($attributeLabels as $field => $label)<option value="{{ $field }}">{{ $label }}</option>@endforeach</template>
<script>
(() => {
    const form = document.querySelector('#profile-form'); const table = form.querySelector('[data-mapping-table]'); const error = form.querySelector('[data-mapping-error]'); const save = form.querySelector('[data-save-button]'); const strategy = form.querySelector('[data-matching-strategy]'); const labels = @json($attributeLabels);
    const update = () => {
        const selects = [...table.querySelectorAll('[data-column-mapping]')]; const chosen = selects.map(select => select.value).filter(value => value !== 'ignore');
        selects.forEach(select => [...select.options].forEach(option => option.disabled = option.value !== 'ignore' && option.value !== select.value && chosen.includes(option.value)));
        const required = ['name', 'price', ...(strategy.value === 'sku' ? ['sku'] : [])]; const missing = required.filter(field => !chosen.includes(field));
        error.textContent = missing.length ? `Укажите столбцы: ${missing.map(field => labels[field]).join(', ')}.` : ''; error.classList.toggle('hidden', missing.length === 0); save.disabled = missing.length > 0;
        selects.forEach(select => { table.querySelector(`[data-column-label="${select.dataset.column}"]`).textContent = select.value === 'ignore' ? `Столбец ${select.dataset.column} · игнорируется` : `Столбец ${select.dataset.column} · ${labels[select.value]}`; });
        const selectedRow = Number(form.querySelector('input[name="data_row"]:checked')?.value ?? 1);
        table.querySelectorAll('[data-source-row]').forEach(row => { const number = Number(row.dataset.sourceRow); const state = row.querySelector('[data-row-state]'); row.classList.remove('bg-slate-100', 'text-muted', 'bg-brand-50', 'bg-white'); if (number < selectedRow) { row.classList.add('bg-slate-100', 'text-muted'); state.textContent = 'Пропущенный заголовок'; } else if (number === selectedRow) { row.classList.add('bg-brand-50'); state.textContent = 'Импорт начинается здесь'; } else { row.classList.add('bg-white'); state.textContent = 'Будет импортировано'; } });
    };
    table.addEventListener('change', update); strategy.addEventListener('change', update);
    form.querySelector('[data-worksheet]').addEventListener('change', async event => {
        const selectedMappings = Object.fromEntries([...table.querySelectorAll('[data-column-mapping]')].map(select => [select.dataset.column, select.value])); const currentRow = Number(form.querySelector('input[name="data_row"]:checked')?.value ?? 1);
        const response = await fetch(`${event.target.dataset.previewUrl}?worksheet=${encodeURIComponent(event.target.value)}`, {headers: {'Accept': 'application/json'}}); if (!response.ok) { error.textContent = 'Не удалось загрузить выбранный лист.'; error.classList.remove('hidden'); save.disabled = true; return; } const preview = await response.json();
        const head = table.tHead.rows[0]; const corner = document.createElement('th'); corner.textContent = 'Начало импорта'; corner.className = 'sticky left-0 z-10 min-w-44'; head.replaceChildren(corner);
        preview.columns.forEach(column => { const th = document.createElement('th'); th.className = 'min-w-52'; const title = document.createElement('span'); title.className = 'mb-2 block text-center text-xs'; title.dataset.columnLabel = column; th.append(title); const select = document.createElement('select'); select.name = `column_mappings[${column}]`; select.dataset.columnMapping = ''; select.dataset.column = column; select.className = 'block min-w-44 w-full rounded-control border border-slate-300 bg-white px-3 py-2.5'; select.setAttribute('aria-label', `Назначение столбца ${column}`); select.innerHTML = document.querySelector('[data-mapping-options]').innerHTML; select.value = selectedMappings[column] ?? 'ignore'; th.append(select); head.append(th); });
        table.tBodies[0].replaceChildren(...preview.rows.map(source => { const row = document.createElement('tr'); row.dataset.sourceRow = source.number; const control = document.createElement('td'); control.className = 'sticky left-0 bg-inherit'; const label = document.createElement('label'); label.className = 'inline-flex min-h-10 cursor-pointer items-center gap-2 whitespace-nowrap text-sm font-medium'; const radio = document.createElement('input'); radio.type = 'radio'; radio.name = 'data_row'; radio.value = source.number; radio.className = 'size-4 accent-brand-600'; radio.checked = Number(source.number) === Math.min(currentRow, preview.rows.length); label.append(radio, document.createTextNode(` С строки ${source.number}`)); const state = document.createElement('span'); state.className = 'mt-1 block text-xs font-semibold'; state.dataset.rowState = ''; control.append(label, state); row.append(control); preview.columns.forEach(column => { const cell = document.createElement('td'); cell.textContent = source.cells[column] ?? ''; row.append(cell); }); return row; }));
        form.querySelector('[data-dimensions]').textContent = `${preview.highest_row} строк, ${preview.highest_column} столбцов`; const truncation = form.querySelector('[data-truncation]'); truncation.textContent = `Показаны первые 100 строк из ${preview.highest_row}. Начальную строку можно выбрать только в этом диапазоне.`; truncation.classList.toggle('hidden', !preview.truncated); update();
    }); update();
})();
</script>
@else
<x-ui.alert type="warning" class="mt-6">Чтобы настроить профиль, сначала загрузите обязательный образец прайс-листа.</x-ui.alert>
@endif
@endsection
