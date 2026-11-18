<x-ui.card>
    <h2 class="font-bold">Результаты проверки версий</h2>
    <p class="mt-2 text-sm text-muted">Версии с одинаковым или более старым днём пропускаются. Для новой версии загрузите файл с более поздней датой публикации.</p>
    @forelse($previews as $preview)
        <div class="mt-4">
            <p class="font-semibold">{{ $preview->original_filename }} · {{ $preview->source_published_at?->timezone('Asia/Dushanbe')->format('d.m.Y') ?? 'Дата не найдена' }}</p>
            <p class="text-sm text-muted">{{ $labels[$reviewReasons[$preview->id]] ?? 'Проверьте профиль и настройки импорта.' }}</p>
            <x-ui.button variant="secondary" :href="route('price-list-imports.show', $preview)">Открыть результат</x-ui.button>
        </div>
    @empty<x-ui.empty-state class="mt-4" title="Нет ожидающих версий" />@endforelse
</x-ui.card>
