@php
    $restoring = old('_sync_form') === 'settings-'.$supplier->id;
    $value = fn ($key, $default = null) => $restoring ? old($key, $default) : $default;
@endphp
<x-ui.card id="synchronization-settings">
    <h2 class="mb-4 font-bold">Настройки загрузки</h2>
    @if(!$configuration->settings_confirmed)
        <x-ui.alert type="warning">Первое сохранение настроек переключит активацию почтовых прайс-листов на правила синхронизации: приоритет, актуальность, включённые источники и пауза будут проверяться перед активацией. При выключенной автоматической загрузке новые почтовые прайсы не активируются автоматически.</x-ui.alert>
    @endif
    <form data-sync-submit method="post" action="{{ route('admin.supplier-sync.update', $supplier) }}" class="mt-4 grid gap-4 md:grid-cols-2">@csrf @method('PUT') <x-mode-version /><input type="hidden" name="_sync_form" value="settings-{{ $supplier->id }}">
        <div class="md:col-span-2">@include('admin.supplier-synchronizations.form-errors')</div>
        <x-ui.select name="automatic_enabled" :restore="$restoring" label="Автоматическая загрузка"><option value="0" @selected(!$value('automatic_enabled', $configuration->automatic_enabled))>Выключена</option><option value="1" @selected($value('automatic_enabled', $configuration->automatic_enabled))>Включена</option></x-ui.select>
        <x-ui.select name="paused" :restore="$restoring" label="Пауза автоматических запусков"><option value="0" @selected(!$value('paused', $configuration->paused))>Без паузы</option><option value="1" @selected($value('paused', $configuration->paused))>Приостановлено</option></x-ui.select>
        <x-ui.input type="number" name="fetch_interval_minutes" :restore="$restoring" label="Интервал проверки, минут" min="1" max="10080" :value="$configuration->fetch_interval_minutes" />
        <x-ui.input type="number" name="freshness_hours" :restore="$restoring" label="Окно актуальности, часов" min="1" max="8760" :value="$configuration->freshness_hours" />
        @foreach($sources as $source => $settings)
            <x-ui.select :name="'sources['.$source.'][enabled]'" :id="'source-'.$source.'-enabled'" :error-key="'sources.'.$source.'.enabled'" :restore="$restoring" :label="$sourceNames[$source].' — источник'"><option value="1" @selected($value('sources.'.$source.'.enabled', $settings['enabled']))>Включён</option><option value="0" @selected(!$value('sources.'.$source.'.enabled', $settings['enabled']))>Выключен</option></x-ui.select>
            <x-ui.input type="number" :name="'sources['.$source.'][priority]'" :id="'source-'.$source.'-priority'" :error-key="'sources.'.$source.'.priority'" :restore="$restoring" :label="'Приоритет: '.$sourceNames[$source].' (1 — первый)'" min="1" max="3" :value="$settings['priority']" />
        @endforeach
        <x-ui.button>Сохранить настройки</x-ui.button>
    </form>
</x-ui.card>
