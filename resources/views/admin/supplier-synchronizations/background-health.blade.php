<x-ui.card>
    <h2 class="mb-3 font-bold">Фоновая загрузка и доступность</h2>
    @foreach($serviceStates as $state)
        <p class="mt-2">{{ $sourceNames[$state->source] }} · {{ $labels[$state->reason] ?? ($state->status === 'ready' ? 'Готов' : 'Требуется проверка') }}</p>
        @if($state->status !== 'ready')<x-ui.button variant="secondary" :href="$state->source === 'email' ? route('admin.gmail.index') : route('admin.supplier-sync.index')">Восстановить подключение</x-ui.button>@endif
        <p class="text-sm text-muted">Проверка: {{ $formatDate($state->last_checked_at) }} · Успешная проверка: {{ $formatDate($state->last_success_at) }} · Пауза до: {{ $formatDate($state->cooldown_until) }}</p>
    @endforeach
    @foreach($observations as $observation)
        <div class="mt-4">
            <p>{{ $sourceNames[$partners->firstWhere('id', $observation->external_supplier_partner_id)->source] }} · {{ $observation->available ? 'Прайс доступен' : 'Прайс отсутствует' }} · Публикация: {{ $formatDate($observation->published_at) }} · {{ $precisionLabels[$observation->date_precision] ?? 'Требуется подтверждение даты' }}</p>
            <p class="text-sm text-muted">Проверен: {{ $formatDate($observation->last_checked_at) }} · Следующая проверка: {{ $formatDate($observation->next_check_at) }}</p>
            @if($observation->available && (!$observation->published_at || $observation->published_at->isFuture() || $tab === 'review'))
                @php($restoring = old('_sync_form') === 'availability-date-'.$observation->id)
                <form method="post" action="{{ route('admin.supplier-sync.availability-date', $observation) }}" class="mt-3 grid gap-3 md:grid-cols-3">@csrf <x-mode-version />
                    <input type="hidden" name="_sync_form" value="availability-date-{{ $observation->id }}">
                    <input type="hidden" name="version_key" value="{{ $observation->version_key }}">
                    <x-ui.input type="datetime-local" step="1" name="published_at" :id="'availability-'.$observation->id.'-published_at'" :restore="$restoring" label="Подтверждённая дата публикации" required />
                    <x-ui.input name="evidence" :id="'availability-'.$observation->id.'-evidence'" :restore="$restoring" label="Основание подтверждения" maxlength="1000" required />
                    <x-ui.button>Подтвердить дату источника</x-ui.button>
                </form>
            @endif
        </div>
    @endforeach
    @foreach($downloads as $download)
        <p class="mt-4">{{ $sourceNames[$download->source] }} · {{ $labels[$download->status] ?? 'Требуется проверка' }} · {{ $download->received_rows }} / {{ $download->total_rows ?? '—' }} строк · Следующая страница: {{ $download->next_page }}</p>
        <p class="text-sm text-muted">{{ $labels[$download->reason] ?? '' }} · Повторы: {{ $download->retry_count }} · Следующая попытка: {{ $formatDate($download->next_retry_at) }}</p>
    @endforeach
    @foreach($incidents as $incident)
        <x-ui.alert type="warning">{{ $sourceNames[$incident->source] }}: {{ $labels[$incident->reason] ?? 'Требуется проверка' }}. {{ $incident->notification_error ? 'Письмо не доставлено; отправка будет повторена.' : ($incident->notified_at ? 'Администраторы уведомлены.' : 'Уведомление в очереди.') }}</x-ui.alert>
    @endforeach
</x-ui.card>
