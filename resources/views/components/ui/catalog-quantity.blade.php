@props(['quantity', 'product'])
<div data-catalog-quantity class="relative min-w-0">
    <x-ui.button type="button" variant="ghost" data-quantity-edit aria-label="Изменить количество {{ $product }} в корзине" class="!px-1.5 !text-xs !text-accent border border-accent-border bg-surface tabular-nums"><span data-cart-quantity>{{ $quantity }}</span>&nbsp;шт.</x-ui.button>
    <input hidden data-quantity-editor type="number" min="1" max="999999" step="1" inputmode="numeric" value="{{ $quantity }}" aria-label="Количество {{ $product }} в корзине" class="min-h-10 w-20 max-w-full rounded-control border border-control-border bg-surface px-1 text-xs text-ink tabular-nums focus-visible:outline-focus">
    <span hidden data-quantity-error role="alert" class="absolute right-0 top-full z-10 w-48 rounded-control bg-surface p-2 text-xs text-danger shadow-panel"></span>
</div>
