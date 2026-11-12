    @foreach($sources as $source => $settings)
        @php
            $partner = $partners->firstWhere('source', $source);
            $ready = $readiness[$source] === null;
        @endphp
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-bold">{{ $sourceNames[$source] }}</h2><x-ui.status-badge :status="$ready ? 'confirmed' : 'pending'" :label="$ready ? 'Профиль готов' : ($readiness[$source] === 'credentials_unavailable' ? 'Требуется подключение' : 'Требуется настройка')" /></div>
            <p class="mt-2 text-sm text-muted">{{ in_array($source, $configuration->enabledSources(), true) ? 'Выбран для загрузки' : 'Не выбран для загрузки' }}</p>
            @if(in_array($source, $configuration->enabledSources(), true) && !$ready)<x-ui.alert type="warning" class="mt-3">Загрузка заблокирована. {{ $readiness[$source] === 'credentials_unavailable' ? 'Проверьте учётные данные и подключение сервиса на странице синхронизации.' : 'Подтвердите сопоставление и настройте активный профиль выбранного источника.' }} Активный каталог сохранён.</x-ui.alert>@endif
            @if($source !== 'email')
                @if($configuration->selected_source === $source)
                    <form data-sync-submit method="post" action="{{ route('admin.supplier-sync.fetch', $supplier) }}" class="mt-3 space-y-3">
                        @csrf <x-mode-version />
                        <input type="hidden" name="_sync_form" value="run-{{ $supplier->id }}">
                        <input type="hidden" name="expected_version" value="{{ $configuration->version }}">
                        <input type="hidden" name="expected_source" value="{{ $source }}">
                        @include('admin.supplier-synchronizations.form-errors', ['restoring' => old('_sync_form') === 'run-'.$supplier->id])
                        <x-ui.button :disabled="!$ready || $supplier->status !== 'active' || $dispatchPaused">Импортировать из {{ $source === 'icorp' ? 'iCorp' : 'PriceList.tj' }}</x-ui.button>
                        <p class="text-sm text-muted">Используются сохранённые настройки. Ручной импорт доступен при паузе автоматической загрузки.</p>
                        @if($supplier->status !== 'active')<p class="text-sm text-warning">Импорт недоступен: поставщик неактивен.</p>@endif
                        @if($dispatchPaused)<p class="text-sm text-warning">Новые импорты временно приостановлены на время переключения источников.</p>@endif
                    </form>
                @else
                    <p class="mt-3 text-sm text-muted">{{ $configuration->usesNewestAvailable() ? 'Загрузка выполняется после сравнения публикаций разрешённых источников.' : 'Для импорта выберите этот источник в настройках загрузки и сохраните настройки.' }}</p>
                @endif
            @endif
            @if($source === 'email')
                <x-ui.button class="mt-3" variant="secondary" href="#email-profile">Профиль почты и маршрутизация вложений</x-ui.button>
            @elseif(!$partner)
                <p class="mt-3">Сначала подтвердите сопоставление с сервисом.</p><x-ui.button class="mt-3" variant="secondary" :href="route('admin.supplier-sync.index', ['service' => $source, 'filter' => 'unmapped'])">Найти сопоставление</x-ui.button>
            @else
        <p class="mt-2 break-words text-sm">Сопоставление: {{ $partner->name }} · {{ $partner->external_id }}</p>
        @php
            $restoring = old('_sync_form') === 'profile-'.$partner->id;
            $fieldValue = fn ($key, $default = null) => $restoring ? old($key, $default) : $default;
        @endphp
        <details class="mt-3" @if($restoring) open @endif><summary class="min-h-10 cursor-pointer py-2 font-semibold">Настроить профиль {{ $sourceNames[$source] }}</summary>
        @php($externalProfile = $partner->profile_configuration ?? \App\Services\ExternalPriceWorkbook::profile())
            <h2 class="mb-3 font-bold">Профиль {{ $sourceNames[$source] }} · {{ $partner->name }}</h2>
            <p class="mb-3 text-sm text-muted">Используется отображаемая цена сервиса. Скидки сервиса сохраняются в исходных данных и повторно не применяются. Столбцы внешней таблицы фиксированы.</p>
            <form data-sync-submit method="post" action="{{ route('admin.supplier-import-profiles.external-profile', [$supplier, $partner]) }}" class="grid gap-4 md:grid-cols-2">@csrf @method('PUT') <x-mode-version />
                <input type="hidden" name="expected_supplier_organization_id" value="{{ $supplier->id }}">
                <input type="hidden" name="expected_profile_version" value="{{ $partner->profile_version }}">
                <input type="hidden" name="expected_source" value="{{ $partner->source }}">
                <input type="hidden" name="expected_external_id" value="{{ $partner->external_id }}">
                <input type="hidden" name="_sync_form" value="profile-{{ $partner->id }}">
                <div class="md:col-span-2">@include('admin.supplier-synchronizations.form-errors')</div>
                <x-ui.select :restore="$restoring" name="profile_active" :id="'profile-'.$partner->id.'-profile_active'" label="Состояние профиля"><option value="0" @selected(!$fieldValue('profile_active', $partner->profile_active))>Черновик</option><option value="1" @selected($fieldValue('profile_active', $partner->profile_active))>Активный</option></x-ui.select>
                <x-ui.select :restore="$restoring" name="matching_strategy" :id="'profile-'.$partner->id.'-matching_strategy'" label="Сопоставление товаров">@foreach(['name' => 'По названию', 'sku' => 'По коду', 'sku_then_name' => 'Код, затем название'] as $value => $label)<option value="{{ $value }}" @selected(($restoring ? old('matching_strategy', $externalProfile['matching_strategy']) : $externalProfile['matching_strategy']) === $value)>{{ $label }}</option>@endforeach</x-ui.select>
                <x-ui.input :restore="$restoring" name="date_formats" :id="'profile-'.$partner->id.'-date_formats'" label="Форматы срока годности через запятую" :value="implode(', ', $externalProfile['date_formats'])" />
                <x-ui.input :restore="$restoring" type="number" name="minimum_valid_rows" :id="'profile-'.$partner->id.'-minimum_valid_rows'" label="Минимум корректных строк" min="1" :value="$externalProfile['automatic']['minimum_valid_rows']" />
                <x-ui.input :restore="$restoring" type="number" name="maximum_error_rows" :id="'profile-'.$partner->id.'-maximum_error_rows'" label="Максимум ошибочных строк" min="0" :value="$externalProfile['automatic']['maximum_error_rows']" />
                <x-ui.input :restore="$restoring" type="number" name="maximum_error_percentage" :id="'profile-'.$partner->id.'-maximum_error_percentage'" label="Максимум ошибок, %" min="0" max="100" step="0.01" :value="$externalProfile['automatic']['maximum_error_percentage']" />
                <x-ui.button>Сохранить профиль</x-ui.button>
            </form>
        </details>
            @endif
        </x-ui.card>
    @endforeach
