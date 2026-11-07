@extends('layouts.app')
@section('content')
<x-ui.page-header title="Мои участия" description="История квалификации вашей аптеки, включая завершённые акции.">
    <x-slot:actions><x-ui.button :href="route('promotions.index')" variant="secondary">Все акции</x-ui.button></x-slot:actions>
</x-ui.page-header>
<div class="grid min-w-0 gap-4 md:grid-cols-2">
@forelse($participants as $participant)
    <x-ui.card class="min-w-0 space-y-3">
        <h2 class="break-words text-lg font-bold">{{ $participant->promotion->title }}</h2>
        <p>{{ $participant->promotion->organization->name }} · До {{ $participant->promotion->ends_on->format('d.m.Y') }}</p>
        <p>Первая квалификация: {{ $participant->qualified_at->timezone('Asia/Dushanbe')->format('d.m.Y H:i') }}</p>
        <p class="font-semibold">{{ $participant->is_eligible ? 'Условия выполнены' : 'Условия сейчас не выполнены' }}</p>
        <p>Общая сумма: {{ $participant->qualifying_total }} TJS · Наибольший заказ: {{ $participant->largest_order_value }} TJS · Заказов: {{ $participant->qualifying_order_count }}</p>
        @can('view', $participant->promotion)<x-ui.button :href="route('promotions.show', $participant->promotion)" variant="secondary">Условия и призы</x-ui.button>@else<p class="text-sm text-muted">Публикация сейчас недоступна.</p>@endcan
    </x-ui.card>
@empty
    <x-ui.empty-state title="Пока нет участий" description="После выполнения условий подтверждёнными заказами ваша аптека будет зарегистрирована автоматически." />
@endforelse
</div>
<div class="mt-4">{{ $participants->links() }}</div>
@endsection
