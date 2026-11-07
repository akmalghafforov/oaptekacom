@extends('layouts.app')
@section('content')
<x-ui.page-header title="Участники акции" :description="$promotion->title">
    <x-slot:actions><x-ui.button :href="route('promotions.edit', $promotion)" variant="secondary">К акции</x-ui.button></x-slot:actions>
</x-ui.page-header>
<x-ui.card>
@if($participants->isEmpty())
    <x-ui.empty-state title="Пока нет участников" description="Аптеки появятся после выполнения условий подтверждёнными заказами." />
@else
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Аптека</th><th>Общая сумма</th><th>Наибольший заказ</th><th>Заказов</th><th>Первая квалификация</th><th>Статус и заказы</th></tr></thead>
        <tbody>@foreach($participants as $participant)
            <tr>
                <td>{{ $participant->pharmacy->name }}</td><td>{{ $participant->qualifying_total }} TJS</td><td>{{ $participant->largest_order_value }} TJS</td><td>{{ $participant->qualifying_order_count }}</td>
                <td>{{ $participant->qualified_at->timezone('Asia/Dushanbe')->format('d.m.Y H:i') }}</td>
                <td><p>{{ $participant->is_eligible ? 'Условия выполнены' : 'Условия сейчас не выполнены' }}</p>
                    <details><summary class="min-h-10 cursor-pointer py-2">Подтверждающие заказы</summary>
                        <ul class="space-y-2">@foreach($contributions->get($participant->pharmacy_organization_id, collect()) as $contribution)
                            <li><a href="{{ route('orders.show', $contribution->order) }}" class="inline-flex min-h-10 items-center text-accent underline">Заказ №{{ $contribution->order_id }} · {{ $contribution->amount }} TJS</a><x-ui.status-badge :status="$contribution->order->status" />
                                @if(! in_array($contribution->order->status, \App\Services\PromotionQualification::COUNTED_STATUSES, true))<span class="text-sm text-muted">Не учитывается</span>@endif
                            </li>
                        @endforeach</ul>
                    </details>
                </td>
            </tr>
        @endforeach</tbody>
    </table></div>
    <div class="mt-4">{{ $participants->links() }}</div>
@endif
</x-ui.card>
@endsection
