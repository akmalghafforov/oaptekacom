@props(['supplier'])
<x-ui.card class="mt-6">
    <h2 class="font-bold">Загрузить прайс-лист</h2>
    <p class="mt-2 text-sm text-muted">Почта и файлы доступны независимо от внешних сервисов. Одна версия на календарный день; одинаковые и более старые даты пропускаются.</p>
    <form method="post" action="{{ route('price-list-imports.store') }}" enctype="multipart/form-data" class="mt-4 grid gap-4 md:grid-cols-3 md:items-end">
        @csrf <x-mode-version />
        <input type="hidden" name="supplier_organization_id" value="{{ $supplier->id }}">
        <x-ui.input name="file" type="file" label="Файл CSV, TSV, XLS или XLSX" accept=".csv,.tsv,.xls,.xlsx" required />
        <x-ui.input name="published_date" type="date" label="Дата публикации (необязательно)" :max="now('Asia/Dushanbe')->toDateString()" hint="Иначе — дата из файла или день загрузки." />
        <x-ui.button>Загрузить</x-ui.button>
    </form>
    @if(!$supplier->importProfile?->is_active)<p class="mt-3 text-sm text-muted">Файл будет сохранён как образец для настройки профиля.</p>@endif
</x-ui.card>
