<x-ui.dialog name="catalog-categories" title="Категории товаров" class="catalog-category-sheet">
    <div class="catalog-category-picker-options">
        <x-ui.button type="button" variant="ghost" class="catalog-category-picker-option" data-mobile-category="" aria-pressed="true">
            <x-ui.catalog-category-icon code="all" class="shrink-0" />
            <span class="catalog-category-picker-copy"><span data-mobile-category-label>Все категории</span><span data-mobile-category-count class="catalog-category-picker-count">— тов.</span></span><span class="catalog-category-picker-check" aria-hidden="true">✓</span>
        </x-ui.button>
        @foreach($categories as $category)
            <x-ui.button type="button" variant="ghost" class="catalog-category-picker-option" data-mobile-category="{{ $category->id }}" aria-pressed="false">
                <x-ui.catalog-category-icon :code="$category->code" class="shrink-0" />
                <span class="catalog-category-picker-copy"><span data-mobile-category-label>{{ $category->label }}</span><span data-mobile-category-count class="catalog-category-picker-count">— тов.</span></span><span class="catalog-category-picker-check" aria-hidden="true">✓</span>
            </x-ui.button>
        @endforeach
    </div>
</x-ui.dialog>
