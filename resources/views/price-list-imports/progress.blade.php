<x-ui.card class="mt-6" data-import-progress data-status-url="{{ request()->fullUrl() }}" data-watch-new="{{ request()->routeIs('price-list-imports.index') ? '1' : '0' }}">
    <h2 class="font-bold">Ход импорта</h2>
    <div class="mt-3 space-y-4" data-progress-entries aria-live="polite">
        @forelse($progressEntries as $entry)
            <div><p class="font-semibold">{{ $entry['title'] }}</p><p>{{ $entry['label'] }}</p><p class="text-sm text-muted">{{ $entry['detail'] }}</p>@if($entry['url'])<a class="inline-flex min-h-10 items-center font-semibold underline" href="{{ $entry['url'] }}">Открыть подробности</a>@endif</div>
        @empty
            <p class="text-muted">Загрузки из iCorp и PriceList.tj появятся здесь после запуска.</p>
        @endforelse
    </div>
    <p class="mt-3 text-sm text-muted" data-progress-feedback role="status">Статус обновляется автоматически.</p>
    <x-ui.button variant="secondary" class="mt-3" :href="request()->fullUrl()">Обновить статус</x-ui.button>
</x-ui.card>
