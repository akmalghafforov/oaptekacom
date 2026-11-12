<div class="divide-y divide-border">
    @forelse($historyRuns as $run)
        <div class="py-4">
            <div class="flex flex-wrap items-center justify-between gap-3"><p class="font-semibold">{{ $formatDate($run->created_at) }} · {{ $labels[$run->trigger] ?? 'Запуск' }}</p><x-ui.status-badge :status="$run->status === 'completed' ? 'confirmed' : $run->status" :label="$labels[$run->status] ?? 'Требуется проверка'" /></div>
            <p class="mt-2 text-sm">{{ $labels[$run->reason] ?? 'Результат ещё не получен' }} · {{ $sourceNames[$run->selected_source] ?? 'Источник не выбран' }}</p>
            <details class="mt-2"><summary class="min-h-10 cursor-pointer py-2 font-semibold">Попытки источников · {{ $run->attempts->count() }}</summary>
                <div class="space-y-3">@foreach($run->attempts as $attempt)
                    <div class="rounded-control border border-border p-3"><p class="font-semibold">{{ $sourceNames[$attempt->source] ?? 'Источник' }} · {{ $labels[$attempt->status] ?? 'Требуется проверка' }}</p><p class="mt-1 text-sm">{{ $labels[$attempt->reason] ?? 'Результат ещё не получен' }}</p>
                        <p class="mt-2 text-sm text-muted">Публикация: {{ isset($attempt->metadata['published_at']) ? $formatDate(\Carbon\CarbonImmutable::parse($attempt->metadata['published_at'])) : 'Не определена' }} · {{ $precisionLabels[$attempt->metadata['date_precision'] ?? ''] ?? 'Точность не определена' }}</p>
                        @if($attempt->import)<a class="inline-flex min-h-10 items-center font-semibold underline" href="{{ route('price-list-imports.show', $attempt->import) }}">Просмотреть импорт</a>@endif
                        <details><summary class="min-h-10 cursor-pointer py-2 text-sm">Технические сведения</summary>
                            @if(isset($attempt->metadata['failure']))
                                <p class="text-xs text-muted">Этап: {{ $attempt->metadata['failure']['stage'] }} · Код: {{ $attempt->metadata['failure']['code'] }}@if($attempt->metadata['failure']['http_status'] ?? null) · HTTP {{ $attempt->metadata['failure']['http_status'] }}@endif</p>
                            @endif<p class="text-xs">Запуск #{{ $run->id }} · Попытка #{{ $attempt->id }} @if($attempt->import) · Импорт #{{ $attempt->import->id }}@endif</p>@if($attempt->metadata['fingerprint'] ?? null)<p class="mt-2 text-xs">Отпечаток содержимого</p><code class="block break-all text-xs">{{ $attempt->metadata['fingerprint'] }}</code>@endif</details>
                    </div>
                @endforeach</div>
            </details>
        </div>
    @empty
        <x-ui.empty-state title="Запусков пока нет" description="Сохраните настройки и запросите загрузку. Результат появится после обработки очереди." />
    @endforelse
</div>
@if($showPagination){{ $runs->links() }}@endif
