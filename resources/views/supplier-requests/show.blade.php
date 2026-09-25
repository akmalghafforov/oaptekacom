@extends('layouts.app')

@section('content')
<x-ui.page-header :title="'Заявка: '.$supplierRequest->supplier_name" :description="'Создана '.$supplierRequest->shared_at->format('d.m.Y H:i')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('supplier-requests.print', $supplierRequest)" target="_blank" variant="secondary">Печать / PDF</x-ui.button>
            <x-ui.button :href="route('supplier-requests.excel', $supplierRequest)" variant="secondary">Excel</x-ui.button>
            <x-ui.button :href="route('supplier-requests.index')" variant="ghost">К архиву</x-ui.button>
        </div>
    </x-slot:actions>
</x-ui.page-header>

<x-ui.card class="mb-5"><div class="grid gap-4 sm:grid-cols-3"><div><p class="text-sm text-muted">Поставщик</p><p class="mt-1 font-semibold">{{ $supplierRequest->supplier_name }}</p></div><div><p class="text-sm text-muted">Позиций</p><p class="mt-1 font-semibold">{{ $supplierRequest->item_count }}</p></div><div><p class="text-sm text-muted">Итого</p><p class="mt-1 text-lg font-bold text-brand-700">{{ $supplierRequest->total }} TJS</p></div></div></x-ui.card>

<div class="table-wrap"><table class="data-table"><thead><tr><th>Товар</th><th>Количество</th><th>Цена</th><th>Сумма</th></tr></thead><tbody>@foreach($supplierRequest->items as $item)<tr><td>{{ $item->product_name }}</td><td>{{ $item->quantity }}</td><td>{{ $item->unit_price }} TJS</td><td class="font-medium">{{ $item->line_total }} TJS</td></tr>@endforeach</tbody><tfoot><tr><td colspan="3" class="px-4 py-3 text-right font-bold">Итого</td><td class="px-4 py-3 font-bold">{{ $supplierRequest->total }} TJS</td></tr></tfoot></table></div>
@endsection
