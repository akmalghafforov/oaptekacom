    @foreach($sources as $source => $settings)
        @php
            $partner = $partners->firstWhere('source', $source);
            $ready = $source === 'email' ? (bool) $supplier->importProfile?->is_active : (bool) ($partner?->profile_active && $partner?->profile_configuration);
        @endphp
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-bold">{{ $settings['priority'] }}. {{ $sourceNames[$source] }}</h2><x-ui.status-badge :status="$ready ? 'confirmed' : 'pending'" :label="$ready ? 'Профиль готов' : 'Требуется настройка'" /></div>
            <p class="mt-2 text-sm text-muted">{{ $settings['enabled'] ? 'Источник включён' : 'Источник выключен' }}</p>
            @if($source === 'email')
                <x-ui.button class="mt-3" variant="secondary" :href="route('admin.supplier-import-profiles.edit', $supplier)">Профиль почты и маршрутизация вложений</x-ui.button>
            @elseif(!$partner)
                <p class="mt-3">Сначала подтвердите сопоставление с сервисом.</p><x-ui.button class="mt-3" variant="secondary" :href="route('admin.supplier-sync.index', ['service' => $source, 'filter' => 'unmapped'])">Найти сопоставление</x-ui.button>
            @else
        @php
            $restoring = old('_sync_form') === 'profile-'.$partner->id;
            $fieldValue = fn ($key, $default = null) => $restoring ? old($key, $default) : $default;
        @endphp
        <details class="mt-3" @if($restoring) open @endif><summary class="min-h-10 cursor-pointer py-2 font-semibold">Настроить профиль {{ $sourceNames[$source] }}</summary>
        @php($profile = $partner->profile_configuration ?? \App\Services\ExternalPriceWorkbook::profile())
            <h2 class="mb-3 font-bold">Профиль {{ $sourceNames[$source] }} · {{ $partner->name }}</h2>
            <p class="mb-3 text-sm text-muted">Используется отображаемая цена сервиса. Скидки сервиса сохраняются в исходных данных и повторно не применяются. Столбцы внешней таблицы фиксированы.</p>
            <form data-sync-submit method="post" action="{{ route('admin.supplier-sync.profile', $partner) }}" class="grid gap-4 md:grid-cols-2">@csrf @method('PUT') <x-mode-version />
                <input type="hidden" name="_sync_form" value="profile-{{ $partner->id }}">
                <div class="md:col-span-2">@include('admin.supplier-synchronizations.form-errors')</div>
                <x-ui.select :restore="$restoring" name="profile_active" :id="'profile-'.$partner->id.'-profile_active'" label="Состояние профиля"><option value="0" @selected(!$fieldValue('profile_active', $partner->profile_active))>Черновик</option><option value="1" @selected($fieldValue('profile_active', $partner->profile_active))>Активный</option></x-ui.select>
                <x-ui.select :restore="$restoring" name="matching_strategy" :id="'profile-'.$partner->id.'-matching_strategy'" label="Сопоставление товаров">@foreach(['name' => 'По названию', 'sku' => 'По коду', 'sku_then_name' => 'Код, затем название'] as $value => $label)<option value="{{ $value }}" @selected(($restoring ? old('matching_strategy', $profile['matching_strategy']) : $profile['matching_strategy']) === $value)>{{ $label }}</option>@endforeach</x-ui.select>
                <x-ui.input :restore="$restoring" name="date_formats" :id="'profile-'.$partner->id.'-date_formats'" label="Форматы срока годности через запятую" :value="implode(', ', $profile['date_formats'])" />
                <x-ui.input :restore="$restoring" type="number" name="minimum_valid_rows" :id="'profile-'.$partner->id.'-minimum_valid_rows'" label="Минимум корректных строк" min="1" :value="$profile['automatic']['minimum_valid_rows']" />
                <x-ui.input :restore="$restoring" type="number" name="maximum_error_rows" :id="'profile-'.$partner->id.'-maximum_error_rows'" label="Максимум ошибочных строк" min="0" :value="$profile['automatic']['maximum_error_rows']" />
                <x-ui.input :restore="$restoring" type="number" name="maximum_error_percentage" :id="'profile-'.$partner->id.'-maximum_error_percentage'" label="Максимум ошибок, %" min="0" max="100" step="0.01" :value="$profile['automatic']['maximum_error_percentage']" />
                <x-ui.button>Сохранить профиль</x-ui.button>
            </form>
        </details>
            @endif
        </x-ui.card>
    @endforeach
