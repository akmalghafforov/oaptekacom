@extends('layouts.app')

@section('content')
<x-ui.page-header title="Архив заявок" description="Только ваши сохранённые заявки поставщикам.">
    <x-slot:actions><x-ui.button :href="route('cart')" variant="secondary">Вернуться в корзину</x-ui.button></x-slot:actions>
</x-ui.page-header>

<x-ui.card class="mb-5">
    <form method="get" class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <x-ui.input name="q" label="Поставщик или товар" :value="$search" placeholder="Начните вводить название" class="flex-1" />
        <x-ui.button variant="secondary">Найти</x-ui.button>
    </form>
</x-ui.card>

<div class="space-y-3">
    @forelse($supplierRequests as $supplierRequest)
        <x-ui.card class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-bold">{{ $supplierRequest->supplier_name }}</h2>
                <p class="mt-1 text-sm text-muted">{{ $supplierRequest->shared_at->format('d.m.Y H:i') }} · {{ $supplierRequest->item_count }} ед. · {{ $supplierRequest->total }} TJS</p>
                <p class="mt-2 text-sm text-slate-600">{{ $supplierRequest->items->pluck('product_name')->take(3)->implode(', ') }}@if($supplierRequest->items->count() > 3) и ещё {{ $supplierRequest->items->count() - 3 }}@endif</p>
            </div>
            <x-ui.button :href="route('supplier-requests.show', $supplierRequest)" variant="secondary">Открыть</x-ui.button>
        </x-ui.card>
    @empty
        <x-ui.empty-state title="Заявок пока нет" description="После успешного обмена заявкой она появится здесь." />
    @endforelse
</div>

<div class="mt-6">{{ $supplierRequests->links() }}</div>
@endsection
