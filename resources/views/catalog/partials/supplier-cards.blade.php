@foreach($offers->groupBy('organization_id') as $supplierOffers)
    @php($supplier = $supplierOffers->first()->organization)
    <x-ui.card class="flex flex-col gap-4" data-supplier-id="{{ $supplier->id }}">
        <div class="flex items-start justify-between gap-3"><div><h3 class="text-lg font-bold">{{ $supplier->name }}</h3><p class="text-sm text-muted">{{ $supplier->city ?: 'Город не указан' }}</p></div><span class="rounded-full bg-brand-50 px-3 py-1 text-sm font-semibold text-brand-700">{{ $supplierOffers->count() }} предл.</span></div>
        <p class="text-sm">Цены от <strong>{{ $supplierOffers->min('price') }} TJS</strong></p>
        <div class="mt-auto flex gap-2"><x-ui.button type="button" data-supplier-open="{{ $supplier->id }}">Открыть</x-ui.button><x-ui.button type="button" variant="secondary" disabled>Информация</x-ui.button></div>
    </x-ui.card>
@endforeach
