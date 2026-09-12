@extends('layouts.app')
@section('content')
    <x-ui.page-header :title="'Профиль импорта: '.$organization->name" description="Настройте лист, строки, столбцы и правила активации." />
    <x-ui.card class="mt-6">
        <form method="post" action="{{ route('admin.supplier-import-profiles.update', $organization) }}" class="space-y-6">
            @csrf @method('PUT')
            <div class="grid gap-4 md:grid-cols-3">
                <x-ui.input name="name" label="Название профиля" :value="$profile->name" required />
                <x-ui.select name="file_type" label="Тип файла">@foreach(['xlsx' => 'XLSX', 'xls' => 'XLS', 'csv' => 'CSV'] as $value => $label)<option value="{{ $value }}" @selected(($profile->file_type?->value ?? $profile->file_type) === $value)>{{ $label }}</option>@endforeach</x-ui.select>
                <x-ui.input name="configuration[worksheet]" label="Имя листа" :value="$profile->configuration['worksheet'] ?? ''" hint="Оставьте пустым для первого листа." />
                <x-ui.input name="configuration[header_row]" type="number" label="Строка заголовков" :value="$profile->configuration['header_row'] ?? 1" min="1" />
                <x-ui.input name="configuration[data_row]" type="number" label="Первая строка данных" :value="$profile->configuration['data_row'] ?? 2" min="1" required />
                <x-ui.input name="configuration[empty_row_limit]" type="number" label="Лимит пустых строк" :value="$profile->configuration['empty_row_limit'] ?? 25" min="1" />
            </div>
            <x-ui.textarea name="sender_emails" label="Email-адреса отправителей" :value="$senderEmails" hint="Один адрес на строку. Адрес может быть назначен только одному поставщику." />
            <h2 class="text-lg font-bold">Сопоставление столбцов</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                @foreach(['name' => 'Товар', 'sku' => 'SKU', 'price' => 'Цена', 'expiration' => 'Срок годности', 'manufacturer' => 'Производитель', 'country' => 'Страна', 'batch' => 'Серия', 'unit' => 'Единица', 'quantity' => 'Количество', 'total' => 'Сумма'] as $field => $label)
                    <x-ui.input name="configuration[mapping][{{ $field }}]" :label="$label" :value="$profile->configuration['mapping'][$field] ?? ''" placeholder="A" :required="$field === 'name'" />
                @endforeach
            </div>
            <div class="grid gap-4 md:grid-cols-3">
                <x-ui.input name="configuration[decimal_separator]" label="Десятичный разделитель" :value="$profile->configuration['decimal_separator'] ?? '.'" />
                <x-ui.select name="configuration[matching_strategy]" label="Сопоставление">@foreach(['name' => 'По названию', 'sku' => 'По SKU', 'sku_then_name' => 'SKU, затем название'] as $value => $label)<option value="{{ $value }}" @selected(($profile->configuration['matching_strategy'] ?? 'name') === $value)>{{ $label }}</option>@endforeach</x-ui.select>
                <x-ui.select name="configuration[activation_mode]" label="Активация"><option value="manual" @selected(($profile->configuration['activation_mode'] ?? 'manual') === 'manual')>Вручную</option><option value="automatic" @selected(($profile->configuration['activation_mode'] ?? 'manual') === 'automatic')>Автоматически</option></x-ui.select>
            </div>
            <x-ui.button>Сохранить профиль</x-ui.button>
        </form>
    </x-ui.card>
@endsection
