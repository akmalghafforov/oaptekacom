    @forelse($previews as $preview)
        @php($restoring = old('_sync_form') === 'date-'.$preview->id)
        <x-ui.card>
            <h2 class="font-bold">Предпросмотр #{{ $preview->id }} · {{ $preview->source_type->label() }}</h2>
            <p class="mt-3 font-semibold">{{ $labels[$reviewReasons[$preview->id]] ?? 'Требуется проверка' }}</p>
            <p class="mt-2 text-sm text-muted">Точность даты: {{ $precisionLabels[$preview->source_date_precision] ?? 'Не определена' }} · Всего строк: {{ $preview->total_rows }}</p>
            <p class="my-3">Корректных строк: {{ $preview->valid_rows }} · Ошибок: {{ $preview->error_rows }} · Дата источника: {{ $preview->source_published_at?->timezone('Asia/Dushanbe')->format('d.m.Y H:i:s') ?? 'Не определена' }}</p>
            <a class="inline-flex min-h-10 items-center font-semibold underline" href="{{ route('price-list-imports.show', $preview) }}">Просмотреть строки</a>
            <form data-sync-submit method="post" action="{{ route('admin.supplier-sync.date', $preview) }}" class="mt-3 grid gap-4 md:grid-cols-2">@csrf <x-mode-version />
                <input type="hidden" name="_sync_form" value="date-{{ $preview->id }}">
                <div class="md:col-span-2">@include('admin.supplier-synchronizations.form-errors')</div>
                <x-ui.input type="datetime-local" step="1" name="published_at" :id="'date-'.$preview->id.'-published_at'" :restore="$restoring" label="Подтверждённая дата публикации (Душанбе)" required />
                <x-ui.input name="evidence" :id="'date-'.$preview->id.'-evidence'" :restore="$restoring" label="Основание подтверждения даты" required />
                <x-ui.button variant="secondary">Подтвердить дату и проверить активацию</x-ui.button>
            </form>
            <form data-sync-submit method="post" action="{{ route('admin.supplier-sync.reject', $preview) }}" class="mt-4">@csrf <x-mode-version /><x-ui.button variant="danger">Отклонить предпросмотр</x-ui.button></form>
        </x-ui.card>
    @empty
        <x-ui.empty-state title="Нет прайс-листов для проверки" description="Новые прайс-листы, требующие решения администратора, появятся здесь." />
    @endforelse
