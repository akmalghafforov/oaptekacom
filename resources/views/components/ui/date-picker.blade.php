@props(['name', 'label', 'id' => null, 'value' => null, 'hint' => null, 'minField' => null])
@php($controlId = $id ?? $name)
<div data-date-picker @if($minField) data-date-min-field="{{ $minField }}" @endif>
    <div class="relative">
        <x-ui.input :name="$name" :id="$controlId" :label="$label" :value="$value" :hint="$hint" type="date" data-date-input {{ $attributes }} />
        <button type="button" data-date-toggle hidden aria-label="Открыть календарь: {{ $label }}" aria-haspopup="dialog" aria-expanded="false" aria-controls="{{ $controlId }}-calendar" class="absolute right-1 top-7 flex size-10 items-center justify-center rounded-control text-accent transition hover:bg-accent-surface focus-visible:outline-focus">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 11h18M8 15h.01M12 15h.01M16 15h.01M8 18h.01M12 18h.01"/></svg>
        </button>
    </div>
    <div id="{{ $controlId }}-calendar" data-date-panel hidden role="dialog" aria-label="Выбор даты: {{ $label }}" class="fixed z-50 max-h-[calc(100dvh-1rem)] w-[21rem] max-w-[calc(100vw-1rem)] overflow-y-auto rounded-panel border border-border bg-surface p-2 text-content shadow-panel">
        <div class="flex items-end gap-1">
            <x-ui.icon-button label="Предыдущий месяц" data-date-prev class="mb-0.5 shrink-0">‹</x-ui.icon-button>
            <div class="grid min-w-0 flex-1 grid-cols-[1fr_4.75rem] gap-1">
                <x-ui.select :name="$controlId.'-month'" label="Месяц" class="px-2!" data-date-month />
                <x-ui.select :name="$controlId.'-year'" label="Год" class="px-2!" data-date-year />
            </div>
            <x-ui.icon-button label="Следующий месяц" data-date-next class="mb-0.5 shrink-0">›</x-ui.icon-button>
        </div>
        <p data-date-heading class="sr-only" aria-live="polite"></p>
        <div class="mt-3 grid grid-cols-7 text-center text-xs font-semibold text-muted" aria-hidden="true">@foreach(['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'] as $day)<span class="py-2">{{ $day }}</span>@endforeach</div>
        <div data-date-grid role="grid" aria-label="Дни месяца"></div>
        <div class="mt-2 flex items-center justify-between gap-2 border-t border-border pt-2">
            <x-ui.button type="button" variant="ghost" data-date-today>Сегодня</x-ui.button>
            <x-ui.button type="button" variant="ghost" data-date-clear>Очистить</x-ui.button>
        </div>
    </div>
</div>
