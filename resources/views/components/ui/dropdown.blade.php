@props(['id', 'label'])
<div {{ $attributes->class(['relative'])->merge(['data-ui-dropdown' => '']) }}>
    <x-ui.button type="button" variant="secondary" data-dropdown-trigger aria-haspopup="menu" aria-expanded="false" :aria-controls="$id">{{ $label }} <span aria-hidden="true" class="ml-2">▾</span></x-ui.button>
    <div id="{{ $id }}" role="menu" data-dropdown-menu hidden class="absolute left-0 top-full z-30 mt-1 min-w-36 rounded-control border border-control-border bg-surface p-1 shadow-panel">
        {{ $slot }}
    </div>
</div>
