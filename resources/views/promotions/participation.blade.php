@if($promotion->purchase_scope !== null)
<x-ui.card class="mb-5 min-w-0 space-y-4">
    <h2 class="text-xl font-bold">Условия участия и призы</h2>
    <p>{{ $promotion->purchase_scope === \App\Enums\PromotionPurchaseScope::Company ? 'Учитываются все товары компании.' : 'Учитываются только позиции этой акции.' }} {{ $promotion->condition_match === \App\Enums\PromotionConditionMatch::All ? 'Выполните все указанные условия.' : 'Выполните любое указанное условие.' }}</p>
    <ul class="list-inside list-disc space-y-2">
        @if($promotion->minimum_total !== null)<li>Общая сумма покупок не менее {{ $promotion->minimum_total }} TJS.</li>@endif
        @if($promotion->minimum_order_value !== null)<li>Хотя бы один заказ на сумму не менее {{ $promotion->minimum_order_value }} TJS.</li>@endif
        @if($promotion->minimum_order_count !== null)<li>Не менее {{ $promotion->minimum_order_count }} заказов.</li>@endif
    </ul>
    <p class="text-sm text-muted">Покупки учитываются с начала акции, но не ранее первой публикации, по {{ $promotion->ends_on->format('d.m.Y') }} включительно (Asia/Dushanbe). Заказы, оформленные во время снятия с публикации, не учитываются. Цены после скидок; заказ засчитывается после подтверждения поставщиком, в том числе после окончания акции. Частично подтверждённые и отменённые заказы не учитываются.</p>
    <h3 class="font-semibold">Призы</h3>
    <ul class="space-y-3">
        @foreach($promotion->prizes as $prize)
            <li class="min-w-0 break-words"><span class="font-semibold">{{ $prize->name }} — {{ $prize->quantity }} шт.</span>@if($prize->description)<p class="whitespace-pre-line text-sm text-muted">{{ $prize->description }}</p>@endif</li>
        @endforeach
    </ul>
    <p class="text-sm text-muted">Участие автоматическое. Победителей определяет организатор.</p>
    @if($progress !== null)
        <div class="space-y-2 border-t border-border pt-4">
            <h3 class="font-semibold">Прогресс вашей аптеки</h3>
            <p>Общая сумма: {{ $progress['qualifying_total'] }} TJS · Наибольший заказ: {{ $progress['largest_order_value'] }} TJS · Заказов: {{ $progress['qualifying_order_count'] }}</p>
            @if($progress['is_eligible'])
                <x-ui.alert type="success">Условия выполнены. Ваша аптека участвует в акции.</x-ui.alert>
            @elseif($participation)
                <x-ui.alert type="warning">Участие зарегистрировано {{ $participation->qualified_at->timezone('Asia/Dushanbe')->format('d.m.Y H:i') }}. Сейчас условия не выполнены: проверьте статусы заказов.</x-ui.alert>
            @else
                <p class="text-sm text-muted">Условия пока не выполнены. После подтверждения подходящих заказов аптека будет зарегистрирована автоматически.</p>
            @endif
            <x-ui.button :href="route('promotions.participations')" variant="secondary">Мои участия</x-ui.button>
        </div>
    @endif
    @can('manage', $promotion)<x-ui.button :href="route('promotions.participants', $promotion)" variant="secondary">Участники акции</x-ui.button>@endcan
</x-ui.card>
@endif
