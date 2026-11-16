    @forelse($previews as $preview)
        @php($restoring = old('_sync_form') === 'date-'.$preview->id)
        <x-ui.card>
            <h2 class="font-bold">Предпросмотр #{{ $preview->id }} · {{ $preview->source_type->label() }}</h2>
            <p class="mt-3 font-semibold">{{ $labels[$reviewReasons[$preview->id]] ?? 'Требуется проверка' }}</p>
            <p class="mt-2 text-sm text-muted">Точность даты: {{ $precisionLabels[$preview->source_date_precision] ?? 'Не определена' }} · Всего строк: {{ $preview->total_rows }}</p>
            <p class="my-3">Корректных строк: {{ $preview->valid_rows }} · Ошибок: {{ $preview->error_rows }} · Дата источника: {{ $preview->source_published_at?->timezone('Asia/Dushanbe')->format('d.m.Y H:i:s') ?? 'Не определена' }}</p>
            <a class="inline-flex min-h-10 items-center font-semibold underline" href="{{ route('price-list-imports.show', $preview) }}">Просмотреть строки</a>
            @if(isset($conflictReviews[$preview->id]) && in_array($reviewReasons[$preview->id], ['review_equal_date_conflict', 'review_date_precision'], true))
                <details class="mt-3" open><summary class="min-h-10 cursor-pointer py-2 font-semibold">Сравнить версии</summary>
                    @foreach($conflictReviews[$preview->id]['others'] as $other)
                        <p class="mt-2">{{ $other->source_type->label() }} · {{ $other->original_filename }} · {{ $formatDate($other->source_published_at) }} · {{ $precisionLabels[$other->source_date_precision] ?? 'Не определена' }} · {{ $other->valid_rows }} строк</p>
                        <p class="text-sm text-muted">Строк только в выбранной версии: {{ $conflictReviews[$preview->id]['differences'][$other->id]['only_candidate'] }} · Только в сравниваемой: {{ $conflictReviews[$preview->id]['differences'][$other->id]['only_other'] }}. Изменение цены, количества или срока считается различием строки.</p><x-ui.button variant="secondary" :href="route('price-list-imports.show', $other)">Строки сравниваемой версии</x-ui.button>
                    @endforeach
                    <p class="mt-3 text-sm text-muted">Содержимое различается или даты пересекаются. Выбор относится только к этим версиям и текущим настройкам; более новый прайс по-прежнему имеет преимущество.</p>
                    <form method="post" data-sync-submit action="{{ route('admin.supplier-sync.resolve-conflict', $preview) }}" class="mt-3 space-y-3">@csrf <x-mode-version />
                        <input type="hidden" name="comparison_token" value="{{ $conflictReviews[$preview->id]['token'] }}">
                        <x-ui.input name="evidence" :id="'conflict-'.$preview->id.'-evidence'" label="Основание выбора этой версии" required maxlength="1000" />
                        <x-ui.button>Выбрать этот прайс после сравнения</x-ui.button>
                    </form>
                </details>
            @endif
            <form data-sync-submit method="post" action="{{ route('admin.supplier-sync.date', $preview) }}" class="mt-3 grid gap-4 md:grid-cols-2">@csrf <x-mode-version />
                <input type="hidden" name="_sync_form" value="date-{{ $preview->id }}">
                <div class="md:col-span-2">@include('admin.supplier-synchronizations.form-errors')</div>
                <x-ui.input type="datetime-local" step="1" name="published_at" :id="'date-'.$preview->id.'-published_at'" :restore="$restoring" label="Подтверждённая дата публикации (Душанбе)" required />
                <x-ui.select name="precision" :id="'date-'.$preview->id.'-precision'" :restore="$restoring" label="Точность подтверждения"><option value="day">Известен только день</option><option value="minute">До минуты</option><option value="second">До секунды</option></x-ui.select>
                <x-ui.input name="evidence" :id="'date-'.$preview->id.'-evidence'" :restore="$restoring" label="Основание подтверждения даты" required />
                <x-ui.button variant="secondary">Подтвердить дату и проверить активацию</x-ui.button>
            </form>
            <form data-sync-submit method="post" action="{{ route('admin.supplier-sync.reject', $preview) }}" class="mt-4">@csrf <x-mode-version /><x-ui.button variant="danger">Отклонить предпросмотр</x-ui.button></form>
        </x-ui.card>
    @empty
        <x-ui.empty-state title="Нет прайс-листов для проверки" description="Новые прайс-листы, требующие решения администратора, появятся здесь." />
    @endforelse
