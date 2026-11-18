<x-ui.card id="synchronization-settings">
    <h2 class="font-bold">Автоматическая внешняя загрузка</h2>
    <p class="mt-2 text-sm text-muted">Проверка списков раз в час. Выбирается самый новый день; при одинаковом дне предпочтителен PriceList-TJ. Полученный почтовый прайс обрабатывается первым.</p>
    <form method="post" action="{{ route('admin.supplier-sync.external-fetch', $supplier) }}" class="mt-4 grid gap-4 md:grid-cols-2 md:items-end">
        @csrf @method('PUT') <x-mode-version />
        <input type="hidden" name="expected_version" value="{{ $configuration->version ?? 0 }}">
        <x-ui.select name="external_fetch_enabled" label="Внешняя загрузка"><option value="1" @selected($supplier->external_fetch_enabled)>Включена</option><option value="0" @selected(!$supplier->external_fetch_enabled)>Выключена</option></x-ui.select>
        <x-ui.input name="exclusion_reason" label="Причина исключения" :value="$supplier->external_fetch_exclusion_reason === 'regular_email' ? 'Регулярная почтовая доставка' : $supplier->external_fetch_exclusion_reason" maxlength="255" />
        <x-ui.button>Сохранить</x-ui.button>
    </form>
    @if($evidence = $supplier->external_fetch_evidence)
        <p class="mt-3 text-sm text-muted">Почтовых доставок: {{ $evidence['deliveries'] ?? 0 }} · {{ $evidence['start'] ?? '—' }} — {{ $evidence['end_exclusive'] ?? '—' }} (не включая последний день). {{ ($evidence['deliveries'] ?? 0) >= 3 ? 'Рекомендация: получать по почте.' : 'Недостаточно сведений для исключения.' }} Проверено: {{ $supplier->external_fetch_assessed_at?->timezone('Asia/Dushanbe')->format('d.m.Y H:i') ?? '—' }}.</p>
    @endif
    @if($initial = $supplier->external_fetch_evidence['approved_initial_history'] ?? null)<p class="mt-2 text-sm text-muted">Начальное основание исключения: {{ $initial['deliveries'] }} доставок за {{ $initial['start'] }} — {{ $initial['end'] }}.</p>@endif
    <p class="mt-2 text-sm text-muted">{{ $supplier->external_fetch_overridden ? 'Выбор администратора сохранён. Ежедневная оценка его не изменит.' : 'Ежедневная оценка обновляет рекомендации.' }}</p>
    <details class="mt-4"><summary class="min-h-10 cursor-pointer py-2 font-semibold">История настроек внешней загрузки</summary>
        @forelse($externalFetchAudit as $entry)
            <p class="mt-2 text-sm">{{ $entry->created_at->timezone('Asia/Dushanbe')->format('d.m.Y H:i') }} · {{ $entry->user_id ? 'Администратор №'.$entry->user_id : 'Начальная настройка' }} · {{ ($entry->after['external_fetch_enabled'] ?? false) ? 'Включена' : 'Выключена' }} · {{ ($entry->after['external_fetch_exclusion_reason'] ?? null) === 'regular_email' ? 'Регулярная почтовая доставка' : ($entry->after['external_fetch_exclusion_reason'] ?? 'Без исключения') }}</p>
        @empty<p class="text-sm text-muted">Изменений пока нет.</p>@endforelse
    </details>
</x-ui.card>
