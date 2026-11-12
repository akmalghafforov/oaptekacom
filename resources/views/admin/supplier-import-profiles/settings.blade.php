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
        <x-ui.select name="selection_mode" :restore="$restoring" label="Режим выбора прайс-листа" hint="В новом режиме сравнивается дата публикации; при равной дате — приоритет. Окно актуальности используется для мониторинга.">
            <option value="" @selected(!$value('selection_mode', $configuration->selection_mode))>Один выбранный источник</option>
            <option value="newest_available" @selected($value('selection_mode', $configuration->selection_mode) === 'newest_available')>Самый новый доступный прайс</option>
        </x-ui.select>
        <x-ui.select name="automatic_enabled" :restore="$restoring" label="Автоматическая загрузка"><option value="0" @selected(!$value('automatic_enabled', $configuration->automatic_enabled))>Выключена</option><option value="1" @selected($value('automatic_enabled', $configuration->automatic_enabled))>Включена</option></x-ui.select>
        <x-ui.select name="paused" :restore="$restoring" label="Пауза автоматических запусков"><option value="0" @selected(!$value('paused', $configuration->paused))>Без паузы</option><option value="1" @selected($value('paused', $configuration->paused))>Приостановлено</option></x-ui.select>
        <x-ui.input type="number" name="fetch_interval_minutes" :restore="$restoring" label="Интервал проверки, минут" min="1" max="10080" :value="$configuration->fetch_interval_minutes" />
        <x-ui.input type="number" name="freshness_hours" :restore="$restoring" label="Окно актуальности, часов" min="1" max="8760" :value="$configuration->freshness_hours" />
        <div class="md:col-span-2">
            <x-ui.select name="selected_source" :restore="$restoring" label="Единственный источник (в режиме одного источника)" hint="Это поле используется в режиме одного выбранного источника. При его ошибке активный каталог сохраняется.">
                <option value="">Выберите источник</option>
                @foreach($sourceNames as $source => $label)
                    @php($mapped = $source === 'email' || $partners->contains('source', $source))
                    <option value="{{ $source }}" @disabled(!$mapped && $configuration->selected_source !== $source) @selected($value('selected_source', $configuration->selected_source) === $source)>{{ $label }}{{ !$mapped ? ' · нет сопоставления' : ($readiness[$source] ? ' · требуется настройка' : '') }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <p class="text-sm text-muted md:col-span-2">В режиме одного источника приоритеты справочные. В режиме самого нового прайса включите разрешённые источники; интервал внешних проверок — от 60 минут. Сопоставление само по себе не разрешает загрузку.</p>
        @foreach($sources as $source => $settings)
            <x-ui.select :name="'sources['.$source.'][enabled]'" :id="'source-'.$source.'-enabled'" :error-key="'sources.'.$source.'.enabled'" :restore="$restoring" :label="'Разрешён в новом режиме: '.$sourceNames[$source]">
                <option value="0" @selected(!$value('sources.'.$source.'.enabled', $configuration->usesNewestAvailable() && $settings['enabled']))>Выключен</option>
                <option value="1" @selected($value('sources.'.$source.'.enabled', $configuration->usesNewestAvailable() && $settings['enabled']))>Включён</option>
            </x-ui.select>
            <x-ui.input type="number" :name="'sources['.$source.'][priority]'" :id="'source-'.$source.'-priority'" :error-key="'sources.'.$source.'.priority'" :restore="$restoring" :label="'Приоритет: '.$sourceNames[$source]" min="1" max="3" :value="$settings['priority']" />
        @endforeach
        <x-ui.button>Сохранить настройки</x-ui.button>
    </form>
    @if($configuration->usesNewestAvailable())
        <form data-sync-submit method="post" action="{{ route('admin.supplier-sync.fetch', $supplier) }}" class="mt-4">@csrf <x-mode-version /><x-ui.button :disabled="$supplier->status !== 'active' || $dispatchPaused">Проверить новые прайсы</x-ui.button></form>
    @endif
</x-ui.card>
