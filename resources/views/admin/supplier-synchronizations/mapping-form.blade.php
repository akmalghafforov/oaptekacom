<div class="space-y-3 border-t border-border py-4">
    <h3 class="font-bold">{{ $serviceNames[$partner->source] }} · {{ $partner->name }}</h3>
    <p class="text-sm text-muted">Обнаружен: {{ $partner->discovered_at->timezone('Asia/Dushanbe')->format('d.m.Y H:i') }} · Внешний код: {{ $partner->external_id }}</p>
    <div class="my-4 space-y-2"><h3 class="font-semibold">Возможные совпадения</h3>@forelse($candidates as $candidate)<p class="break-words text-sm">{{ $candidate['name'] }} (#{{ $candidate['id'] }}) — {{ implode('; ', $candidate['evidence']) }}</p>@empty<p class="text-sm text-muted">Совпадений по названию и телефонам нет. Выберите поставщика вручную или создайте нового.</p>@endforelse</div>
    <form method="post" data-sync-submit data-mapping-form action="{{ route('admin.supplier-sync.map', $partner) }}" class="space-y-4">@csrf <x-mode-version />
        <input type="hidden" name="_sync_form" value="mapping-{{ $partner->id }}">
        @include('admin.supplier-synchronizations.form-errors')
        <x-ui.select name="mapping_mode" :id="'mapping-'.$partner->id.'-mode'" :restore="$restoring" label="Способ сопоставления" data-mapping-mode required><option value="">Выберите действие</option><option value="existing" @selected($value('mapping_mode') === 'existing')>Выбрать существующего</option><option value="new" @selected($value('mapping_mode') === 'new')>Создать нового</option></x-ui.select>
        <div data-mapping-panel="existing"><x-ui.select name="supplier_organization_id" :id="'mapping-'.$partner->id.'-supplier'" :restore="$restoring" label="Существующий поставщик"><option value="">Выберите поставщика</option>@foreach($allSuppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) $value('supplier_organization_id', $partner->supplier_organization_id) === (string) $supplier->id)>{{ $supplier->name }} (#{{ $supplier->id }})</option>@endforeach</x-ui.select></div>
        <div data-mapping-panel="new" class="grid gap-4 md:grid-cols-3">
            <x-ui.input name="name" :id="'mapping-'.$partner->id.'-name'" :restore="$restoring" label="Название нового поставщика" :value="$partner->name" />
            <x-ui.input name="city" :id="'mapping-'.$partner->id.'-city'" :restore="$restoring" label="Город нового поставщика" />
            <x-ui.input name="phone" :id="'mapping-'.$partner->id.'-phone'" :restore="$restoring" label="Телефон нового поставщика" />
        </div>
        <x-ui.select name="confirmed" :id="'mapping-'.$partner->id.'-confirmed'" :restore="$restoring" label="Подтверждение администратора" required><option value="">Проверьте название и телефоны</option><option value="1" @selected((string) $value('confirmed') === '1')>Подтверждаю соответствие</option></x-ui.select>
        <div class="flex flex-wrap justify-end gap-3">
            <x-ui.button variant="secondary" :href="route('admin.supplier-sync.index', request()->except('edit'))">Отмена</x-ui.button>
            <x-ui.button>Сохранить сопоставление</x-ui.button>
        </div>
    </form>

</div>
