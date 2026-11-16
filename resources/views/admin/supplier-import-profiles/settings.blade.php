@php
    $restoring = old('_sync_form') === 'settings-'.$supplier->id;
    $value = fn ($key, $default = null) => $restoring ? old($key, $default) : $default;
@endphp
<x-ui.card id="synchronization-settings">
    <h2 class="mb-4 font-bold">Настройки загрузки</h2>
    @if(!$configuration->settings_confirmed)
        <x-ui.alert type="warning">Первое сохранение настроек переключит активацию почтовых прайс-листов на правила синхронизации: выбранный источник, актуальность и пауза будут проверяться перед активацией. При выключенной автоматической загрузке новые почтовые прайсы не активируются автоматически.</x-ui.alert>
    @endif
    <form data-sync-submit method="post" action="{{ route('admin.supplier-import-profiles.settings', $supplier) }}" class="mt-4 grid gap-4 md:grid-cols-2">@csrf @method('PUT') <x-mode-version /><input type="hidden" name="_sync_form" value="settings-{{ $supplier->id }}">
        <input type="hidden" name="expected_version" value="{{ $configuration->version ?? 0 }}">
        <div class="md:col-span-2">@include('admin.supplier-synchronizations.form-errors')</div>
        <x-ui.select name="selection_mode" :restore="$restoring" label="Режим выбора прайс-листа" hint="В новом режиме сравнивается дата публикации. Окно актуальности используется для мониторинга.">
            <option value="" @selected(!$value('selection_mode', $configuration->selection_mode))>Один выбранный источник</option>
            <option value="newest_available" @selected($value('selection_mode', $configuration->selection_mode) === 'newest_available')>Самый новый доступный прайс</option>
        </x-ui.select>
        <x-ui.select name="automatic_enabled" :restore="$restoring" label="Автоматическая загрузка"><option value="0" @selected(!$value('automatic_enabled', $configuration->automatic_enabled))>Выключена</option><option value="1" @selected($value('automatic_enabled', $configuration->automatic_enabled))>Включена</option></x-ui.select>
        <x-ui.select name="paused" :restore="$restoring" label="Пауза автоматических запусков"><option value="0" @selected(!$value('paused', $configuration->paused))>Без паузы</option><option value="1" @selected($value('paused', $configuration->paused))>Приостановлено</option></x-ui.select>
        <x-ui.input type="number" name="fetch_interval_minutes" :restore="$restoring" label="Интервал проверки, минут" min="1" max="10080" :value="$configuration->fetch_interval_minutes" />
        <x-ui.input type="number" name="freshness_hours" :restore="$restoring" label="Окно актуальности, часов" min="1" max="8760" :value="$configuration->freshness_hours" />
        <details class="md:col-span-2" @if(!$configuration->usesNewestAvailable() || $errors->has('selected_source')) open @endif><summary class="min-h-10 cursor-pointer py-2 font-semibold">Дополнительно: использовать только один источник</summary>
            <x-ui.select name="selected_source" :restore="$restoring" label="Единственный источник (в режиме одного источника)" hint="Это поле используется в режиме одного выбранного источника. При его ошибке активный каталог сохраняется.">
                <option value="">Выберите источник</option>
                @foreach($sourceNames as $source => $label)
                    @php($mapped = $source === 'email' || $partners->contains('source', $source))
                    <option value="{{ $source }}" @disabled(!$mapped && $configuration->selected_source !== $source) @selected($value('selected_source', $configuration->selected_source) === $source)>{{ $label }}{{ !$mapped ? ' · нет сопоставления' : ($readiness[$source] ? ' · требуется настройка' : '') }}</option>
                @endforeach
            </x-ui.select>
        </details>
        <p class="text-sm text-muted md:col-span-2">В режиме одного источника приоритеты справочные. В режиме самого нового прайса включите разрешённые источники; интервал внешних проверок — от 60 минут. Сопоставление само по себе не разрешает загрузку.</p>
        @foreach($sources as $source => $settings)
            <x-ui.select :name="'sources['.$source.'][enabled]'" :id="'source-'.$source.'-enabled'" :error-key="'sources.'.$source.'.enabled'" :restore="$restoring" :label="'Разрешён в новом режиме: '.$sourceNames[$source]">
                <option value="0" @selected(!$value('sources.'.$source.'.enabled', $configuration->usesNewestAvailable() && $settings['enabled']))>Выключен</option>
                <option value="1" @selected($value('sources.'.$source.'.enabled', $configuration->usesNewestAvailable() && $settings['enabled']))>Включён</option>
            </x-ui.select>
            <details @if($errors->has('sources.'.$source.'.priority')) open @endif><summary class="min-h-10 cursor-pointer py-2 text-sm font-semibold">Дополнительно: приоритет {{ $sourceNames[$source] }}</summary><x-ui.input type="number" :name="'sources['.$source.'][priority]'" :id="'source-'.$source.'-priority'" :error-key="'sources.'.$source.'.priority'" :restore="$restoring" :label="'Приоритет: '.$sourceNames[$source]" min="1" max="3" :value="$settings['priority']" /></details>
        @endforeach
        <details class="md:col-span-2"><summary class="min-h-10 cursor-pointer py-2 font-semibold">Дополнительно: предпочтение при точно равных датах</summary><x-ui.select name="tie_source" :restore="$restoring" label="Предпочтение только для равных дат" hint="Более новый прайс всегда выигрывает. Изменения того же источника при равной дате требуют проверки."><option value="">Всегда запрашивать проверку разных версий</option>@foreach($sourceNames as $source => $label)<option value="{{ $source }}" @selected($value('tie_source', $configuration->workflow_policy['tie_source'] ?? null) === $source)>{{ $label }}</option>@endforeach</x-ui.select></details>
        <div class="flex flex-wrap gap-3 md:col-span-2"><x-ui.button name="action" value="save" variant="secondary">Сохранить настройки</x-ui.button><x-ui.button name="action" value="enable">Включить автоматическое обновление и проверить</x-ui.button></div><p class="text-sm text-muted md:col-span-2">Перед включением проверьте разрешённые источники и подтвердите, что они представляют тот же каталог поставщика и коммерческие условия. Кнопка также включает проверку конфликтов: разные версии с равной или пересекающейся датой требуют решения. Кнопка включает перечисленные источники по расписанию и запускает первую проверку. Сопоставление и профиль сами по себе загрузку не включают.</p>
    </form>
    @if($configuration->usesNewestAvailable())
        <form data-sync-submit method="post" action="{{ route('admin.supplier-sync.fetch', $supplier) }}" class="mt-4">@csrf <x-mode-version /><x-ui.button :disabled="$supplier->status !== 'active' || $dispatchPaused">Проверить новые прайсы</x-ui.button></form>
    @endif
</x-ui.card>
