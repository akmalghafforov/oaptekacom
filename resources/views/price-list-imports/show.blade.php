@extends('layouts.app')
@section('content')
    <x-ui.page-header :title="$import->original_filename" :description="$import->supplier->name.' · загружен '. $import->created_at->format('d.m.Y H:i')">
        <x-slot:actions><div class="flex flex-wrap gap-2"><a href="{{ route('price-list-imports.download', $import) }}"><x-ui.button type="button" variant="secondary">Скачать исходник</x-ui.button></a>@if($import->status === \App\Enums\PriceListImportStatus::Preview)<form method="post" action="{{ route('price-list-imports.commit', $import) }}">@csrf<x-ui.button>Активировать</x-ui.button></form>@elseif($import->status === \App\Enums\PriceListImportStatus::Failed)<form method="post" action="{{ route('price-list-imports.retry', $import) }}">@csrf<x-ui.button>Повторить</x-ui.button></form>@endif</div></x-slot:actions>
    </x-ui.page-header>
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach(['total_rows' => 'Всего', 'valid_rows' => 'Корректные', 'warning_rows' => 'С предупреждением', 'error_rows' => 'Ошибки', 'skipped_rows' => 'Пропущены'] as $field => $label)<x-ui.card><p class="text-sm text-muted">{{ $label }}</p><p class="mt-1 text-2xl font-bold">{{ $import->{$field} }}</p></x-ui.card>@endforeach
    </div>
    @if($import->failure_message)<x-ui.alert type="danger" class="mt-6">{{ $import->failure_message }}</x-ui.alert>@endif
    <x-ui.card class="mt-6"><h2 class="font-bold">Снимок профиля</h2><p class="mt-2 text-sm text-muted">Лист: {{ $import->profile_snapshot['worksheet'] ?? 'первый' }} · начало данных: {{ $import->profile_snapshot['data_row'] }} · сопоставление: {{ $import->profile_snapshot['matching_strategy'] }}</p></x-ui.card>
    <div class="mt-6 table-wrap"><table class="data-table"><thead><tr><th>Строка</th><th>Товар</th><th>Цена</th><th>Действие</th><th>Результат</th></tr></thead><tbody>@forelse($rows as $row)<tr><td>{{ $row->source_row }}</td><td>{{ $row->parsed_values['name'] ?? '' }}</td><td>{{ $row->parsed_values['price'] ?? '' }}</td><td>{{ $row->planned_action?->value }}</td><td><span class="font-semibold">{{ $row->disposition->value }}</span>@foreach($row->errors ?? [] as $error)<p class="text-sm text-danger">{{ $error }}</p>@endforeach @foreach($row->warnings ?? [] as $warning)<p class="text-sm text-warning">{{ $warning }}</p>@endforeach</td></tr>@empty<tr><td colspan="5">Строки ещё не обработаны.</td></tr>@endforelse</tbody></table></div>
    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
