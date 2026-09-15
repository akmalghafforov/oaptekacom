@extends('layouts.app')
@section('content')
    <x-ui.page-header title="Проверка категорий" description="Единая очередь нераспознанных товаров и результатов, требующих решения." />
    <x-ui.card class="mt-6">
        <form method="get" class="grid gap-4 md:grid-cols-4">
            <x-ui.select name="status" label="Статус"><option value="">Все</option><option value="review_required" @selected(request('status') === 'review_required')>Требует проверки</option><option value="unmatched" @selected(request('status') === 'unmatched')>Не распознано</option></x-ui.select>
            <x-ui.select name="category" label="Категория-кандидат"><option value="">Все</option>@foreach($categories as $category)<option value="{{ $category->code }}" @selected(request('category') === $category->code)>{{ $category->label }}</option>@endforeach</x-ui.select>
            <x-ui.input name="supplier" label="ID поставщика" value="{{ request('supplier') }}" inputmode="numeric" />
            <x-ui.input name="age" label="Старше, дней" value="{{ request('age') }}" inputmode="numeric" />
            <x-ui.button type="submit" variant="secondary">Применить фильтры</x-ui.button>
        </form>
    </x-ui.card>
    <div class="mt-6 table-wrap"><table class="data-table"><thead><tr><th>Дата</th><th>Поставщик</th><th>Товар</th><th>Статус</th><th>Кандидаты</th><th>Импорт</th></tr></thead><tbody>@forelse($rows as $row)<tr><td>{{ $row->created_at->format('d.m.Y H:i') }}</td><td>{{ $row->import->supplier->name }}</td><td>{{ $row->original_product_name }}</td><td>{{ $row->categorization_status === 'review_required' ? 'Требует проверки' : 'Не распознано' }}</td><td>@foreach($row->category_candidates ?? [] as $candidate)<p>{{ $candidate['label'] }} · {{ $candidate['confidence'] }}% · {{ $candidate['decision'] }}</p>@endforeach</td><td><a class="font-semibold text-brand underline" href="{{ route('price-list-imports.show', ['import' => $row->price_list_import_id, 'categorization_status' => $row->categorization_status]) }}">Открыть</a></td></tr>@empty<tr><td colspan="6">Очередь проверки пуста.</td></tr>@endforelse</tbody></table></div>
    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
