@props(['status', 'label' => null])
@php
    $labels = [
        'received' => 'Получен', 'confirmed' => 'Подтверждён', 'partially_confirmed' => 'Подтверждён частично',
        'preparing' => 'Собирается', 'ready' => 'Готов', 'delivering' => 'В доставке', 'received_by_customer' => 'Получен покупателем',
        'cancelled' => 'Отменён', 'pending' => 'Ожидает проверки', 'approved' => 'Подтверждён', 'rejected' => 'Отклонён',
        'processing' => 'Обрабатывается', 'preview' => 'Ожидает проверки', 'committing' => 'Активируется',
        'completed' => 'Активен', 'active' => 'Активна', 'blocked' => 'Заблокирована', 'superseded' => 'Заменён', 'failed' => 'Ошибка',
    ];
    $positive = ['received', 'confirmed', 'received_by_customer', 'approved', 'completed', 'active'];
    $negative = ['cancelled', 'rejected', 'failed', 'blocked'];
    $style = in_array($status, $negative, true) ? 'bg-danger-surface text-danger ring-danger-border' : (in_array($status, $positive, true) ? 'bg-accent-surface text-accent ring-success-border' : 'bg-warning-surface text-warning ring-warning-border');
@endphp
<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $style }}">{{ $label ?? $labels[$status] ?? $status }}</span>
