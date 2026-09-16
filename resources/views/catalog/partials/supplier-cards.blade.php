@foreach($offers->groupBy('organization_id') as $supplierOffers)
    @php($supplier = $supplierOffers->first()->organization)
    <x-ui.card class="catalog-supplier-card flex flex-col gap-4" data-mobile-result-shell="suppliers" data-supplier-id="{{ $supplier->id }}">
        <div class="flex items-start gap-3">
            <span class="catalog-supplier-avatar md:hidden" aria-hidden="true">{{ str($supplier->name)->trim()->substr(0, 1)->upper() }}</span>
            <div class="min-w-0 grow"><h3 class="text-lg font-bold">{{ $supplier->name }}</h3><p class="text-sm text-muted">{{ $supplier->city ?: 'Город не указан' }}</p></div>
            <span class="catalog-supplier-count rounded-full bg-brand-50 px-3 py-1 text-sm font-semibold text-brand-700">{{ $supplierOffers->count() }} предл.</span>
        </div>
        <div class="catalog-supplier-meta md:hidden"><span>Прайс и цены открыты</span><span>Без отзывов</span></div>
        <p class="catalog-supplier-price text-sm">Цены от <strong>{{ $supplierOffers->min('price') }} TJS</strong></p>
        <div class="catalog-supplier-actions mt-auto flex gap-2"><x-ui.button type="button" data-supplier-open="{{ $supplier->id }}">Открыть</x-ui.button><x-ui.button type="button" variant="secondary" disabled>Информация</x-ui.button></div>
    </x-ui.card>
@endforeach
