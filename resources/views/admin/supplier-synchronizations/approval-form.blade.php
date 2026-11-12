@php
    $reviewValue = fn ($key, $default = null) => $reviewRestoring ? old($key) : $default;
    $internalDefault = $partner->supplier_organization_id ?? (!app(\App\Services\SupplierSuggestionMatcher::class)->ambiguous($candidates) ? ($candidates[0]['id'] ?? null) : null);
    $reviewOptions = $rowPartners->keyBy('source')->map(fn ($record) => collect([$record]));
    foreach ($serviceNames as $source => $label) {
        if (!$reviewOptions->has($source)) {
            $reviewOptions->put($source, collect($rowSuggestions['external'][$source])->map(fn ($candidate) => $allPartners->firstWhere('id', $candidate['id'])));
        }
    }
    if ($reviewRestoring) {
        foreach ($allPartners->whereIn('id', array_values(old('partner_ids', []))) as $selectedRecord) {
            if (!$reviewOptions[$selectedRecord->source]->contains('id', $selectedRecord->id)) {
                $reviewOptions[$selectedRecord->source]->push($selectedRecord);
            }
        }
    }
    $reviewRecords = $reviewOptions->flatten(1)->unique('id');
@endphp
<form method="post" data-sync-submit data-mapping-form action="{{ route('admin.supplier-sync.approve') }}" class="space-y-4 border-t border-border py-4">
    @csrf <x-mode-version />
    @include('admin.supplier-synchronizations.list-context')
    <input type="hidden" name="_sync_form" value="approve-{{ $partner->id }}">
    @include('admin.supplier-synchronizations.form-errors', ['restoring' => $reviewRestoring])
    <h3 class="break-words font-bold">Сопоставить поставщика · {{ $partner->name }}</h3>
    <p class="text-sm text-muted">Выберите записи, которые относятся к одному поставщику. Можно подтвердить только часть источников. Баллы определяют порядок кандидатов и не являются вероятностью.</p>
    @if($rowSuggestions['ambiguous'])<x-ui.alert type="warning">Есть несколько близких кандидатов. Выберите нужные записи явно.</x-ui.alert>@endif
    @php
        $reviewSnapshot = [
            'partner_versions' => $reviewRecords->mapWithKeys(fn ($record) => [$record->id => $record->profile_version])->all(),
            'partner_evidence' => $partnerEvidence->only($reviewRecords->pluck('id')->all())->all(),
            'supplier_versions' => $allSuppliers->mapWithKeys(fn ($record) => [$record->id => $configurations->get($record->id)?->version ?? 0])->all(),
            'supplier_evidence' => $supplierEvidence->all(),
        ];
        if ($reviewRestoring) {
            foreach ($reviewSnapshot as $key => $snapshot) {
                $reviewSnapshot[$key] = old($key, $snapshot);
            }
        }
    @endphp
    <input type="hidden" name="review_snapshot" value="{{ json_encode($reviewSnapshot, JSON_UNESCAPED_UNICODE) }}">
    <div class="grid gap-4 md:grid-cols-2">
        @foreach($serviceNames as $source => $label)
            @php
                $options = $reviewOptions[$source];
                $sourceCandidates = $rowSuggestions['external'][$source];
                $defaultPartner = $rowPartners->firstWhere('source', $source)?->id ?? (!app(\App\Services\SupplierSuggestionMatcher::class)->ambiguous($sourceCandidates) ? ($sourceCandidates[0]['id'] ?? null) : null);
            @endphp
            <div class="min-w-0 space-y-3">
                <x-ui.select :name="'partner_ids['.$source.']'" :error-key="'partner_ids.'.$source" :id="'approve-'.$partner->id.'-'.$source" :restore="$reviewRestoring" :label="$label">
                    <option value="">Не включать в подтверждение</option>
                    @foreach($options as $record)<option value="{{ $record->id }}" @selected((string) $reviewValue('partner_ids.'.$source, $defaultPartner) === (string) $record->id)>{{ $record->name }} (#{{ $record->id }})</option>@endforeach
                </x-ui.select>
                @foreach($options as $record)
                    <p class="break-words text-sm text-muted">{{ $record->name }} · {{ $reviewSuggestions[$record->id]['city_label'] }} · {{ implode(', ', $record->phones ?? []) ?: 'Телефоны не указаны' }}</p>
                @endforeach
                @foreach($sourceCandidates as $candidate)
                    <p class="break-words text-sm">{{ $candidate['name'] }} (#{{ $candidate['id'] }}) · Баллы ранжирования: {{ round($candidate['score'], 1) }} · {{ implode('; ', $candidate['evidence']) }}</p>
                @endforeach
            </div>
        @endforeach
    </div>
    @if($candidates !== [])
        <div class="space-y-2"><h4 class="font-semibold">Предположения: наш поставщик</h4>
        @foreach($candidates as $candidate)
            @php($candidateSupplier = $allSuppliers->firstWhere('id', $candidate['id']))
            <p class="break-words text-sm text-muted">{{ $candidateSupplier->city ?: 'Город неизвестен' }} · {{ implode(', ', array_filter([$candidateSupplier->phone, ...($candidateSupplier->additional_phones ?? [])])) ?: 'Телефоны не указаны' }}</p>
            <p class="break-words text-sm">{{ $candidate['name'] }} (#{{ $candidate['id'] }}) · Баллы ранжирования: {{ round($candidate['score'], 1) }} · {{ implode('; ', $candidate['evidence']) }}</p>@endforeach
        </div>
    @endif
    <x-ui.select name="mapping_mode" :id="'approve-'.$partner->id.'-mode'" :restore="$reviewRestoring" label="Наш поставщик: способ сопоставления" data-mapping-mode required>
        <option value="">Выберите действие</option><option value="existing" @selected($reviewValue('mapping_mode', 'existing') === 'existing')>Выбрать существующего</option><option value="new" @selected($reviewValue('mapping_mode') === 'new')>Создать нового</option>
    </x-ui.select>
    <div data-mapping-panel="existing" class="space-y-3">
        <x-ui.select name="supplier_organization_id" :id="'approve-'.$partner->id.'-supplier'" :restore="$reviewRestoring" label="Наш поставщик">
            <option value="">Выберите поставщика</option>
            @foreach($allSuppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) $reviewValue('supplier_organization_id', $internalDefault) === (string) $supplier->id)>{{ $supplier->name }} (#{{ $supplier->id }}) · {{ $supplier->city ?: 'Город неизвестен' }} · {{ implode(', ', array_filter([$supplier->phone, ...($supplier->additional_phones ?? [])])) ?: 'Телефоны не указаны' }}</option>@endforeach
        </x-ui.select>
    </div>
    <div data-mapping-panel="new" class="grid gap-4 md:grid-cols-3">
        <x-ui.input name="name" :id="'approve-'.$partner->id.'-name'" :restore="$reviewRestoring" label="Название нового поставщика" :value="$partner->name" />
        <x-ui.input name="city" :id="'approve-'.$partner->id.'-city'" :restore="$reviewRestoring" label="Город нового поставщика" :value="$partner->metadata['city'] ?? null" />
        <x-ui.input name="phone" :id="'approve-'.$partner->id.'-phone'" :restore="$reviewRestoring" label="Телефон нового поставщика" />
    </div>
    <x-ui.select name="confirmed" :id="'approve-'.$partner->id.'-confirmed'" :restore="$reviewRestoring" label="Подтверждение администратора" required><option value="">Проверьте названия, города и телефоны</option><option value="1" @selected((string) $reviewValue('confirmed') === '1')>Подтверждаю соответствие выбранных записей</option></x-ui.select>
    <x-ui.button>Подтвердить сопоставление</x-ui.button>
</form>
