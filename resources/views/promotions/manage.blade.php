@extends('layouts.app')
@section('content')
<x-ui.page-header title="Управление акциями" description="Черновики, публикации и модерация"><x-slot:actions><div class="flex flex-wrap gap-2"><x-ui.button :href="route('promotion-quotes.index')" variant="secondary">Запросы цены</x-ui.button><x-ui.button :href="route('promotions.create')">Создать акцию</x-ui.button></div></x-slot:actions></x-ui.page-header>
<x-ui.card>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Акция</th><th>Компания</th><th>Период</th><th>Статус</th><th>Действие</th></tr></thead><tbody>
@forelse($promotions as $promotion)<tr><td>{{ $promotion->title }}</td><td>{{ $promotion->organization->name }}</td><td>{{ $promotion->starts_on->format('d.m.Y') }} — {{ $promotion->ends_on->format('d.m.Y') }}</td><td><x-ui.status-badge :status="$promotion->status->value" :label="$promotion->status->label()" /></td><td><x-ui.button :href="route('promotions.edit', $promotion)" variant="secondary">Открыть</x-ui.button></td></tr>@empty<tr><td colspan="5">Акций пока нет.</td></tr>@endforelse
</tbody></table></div><div class="mt-4">{{ $promotions->links() }}</div>
</x-ui.card>
@endsection
