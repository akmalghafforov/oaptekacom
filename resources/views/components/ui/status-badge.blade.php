@props(['status'])
@php
    $labels = [
        'received' => 'Получен', 'confirmed' => 'Подтверждён', 'partially_confirmed' => 'Подтверждён частично',
        'preparing' => 'Собирается', 'ready' => 'Готов', 'delivering' => 'В доставке', 'received_by_customer' => 'Получен покупателем',
        'cancelled' => 'Отменён', 'pending' => 'Ожидает проверки', 'approved' => 'Подтверждён', 'rejected' => 'Отклонён',
        'processing' => 'Обрабатывается', 'preview' => 'Ожидает проверки', 'committing' => 'Активируется',
        'completed' => 'Активен', 'superseded' => 'Заменён', 'failed' => 'Ошибка',
    ];
    $positive = ['received', 'confirmed', 'received_by_customer', 'approved', 'completed'];
    $negative = ['cancelled', 'rejected', 'failed'];
    $style = in_array($status, $negative, true) ? 'bg-red-50 text-danger ring-red-200' : (in_array($status, $positive, true) ? 'bg-brand-50 text-brand-700 ring-brand-200' : 'bg-amber-50 text-warning ring-amber-200');
@endphp
<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $style }}">{{ $labels[$status] ?? $status }}</span>
