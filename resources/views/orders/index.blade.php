@extends('layouts.app')
@section('content')
<x-ui.page-header title="Заказы" description="История заказов вашей организации." />
@if($orders->isNotEmpty())<div class="table-wrap"><table class="data-table"><thead><tr><th>№</th><th>Поставщик</th><th>Статус</th><th>Сумма</th></tr></thead><tbody>@foreach($orders as $order)<tr><td><a class="font-semibold text-brand-700 hover:underline" href="{{ route('orders.show', $order) }}">{{ $order->id }}</a></td><td>{{ $order->supplier->name }}</td><td><x-ui.status-badge :status="$order->status" /></td><td class="font-medium">{{ $order->total }} TJS</td></tr>@endforeach</tbody></table></div>@else<x-ui.empty-state title="Заказов пока нет" description="Оформленные заказы появятся здесь." />@endif
<div class="mt-6">{{ $orders->links() }}</div>
@endsection
