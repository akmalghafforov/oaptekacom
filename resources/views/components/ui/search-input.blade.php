@props(['name', 'label', 'value' => '', 'placeholder' => '', 'hint' => null])
<div class="grid min-w-0 gap-1.5">
    <label for="{{ $attributes->get('id', $name) }}" class="text-sm font-semibold text-ink">{{ $label }}</label>
    <div class="relative">
        <svg class="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        <input name="{{ $name }}" id="{{ $attributes->get('id', $name) }}" value="{{ $value }}" placeholder="{{ $placeholder }}" {{ $attributes->except(['id'])->class('min-h-12 w-full rounded-control border border-slate-300 bg-white py-3 pl-11 pr-12 text-ink placeholder:text-slate-400 focus:border-brand-600 focus:ring-2 focus:ring-brand-100') }}>
        <button type="button" data-search-clear aria-label="Очистить поиск" class="absolute right-2 top-1/2 hidden min-h-10 min-w-10 -translate-y-1/2 place-items-center rounded-full text-muted hover:bg-slate-100" hidden>×</button>
    </div>
    @if($hint)<p class="text-xs text-muted" id="{{ $name }}-hint">{{ $hint }}</p>@endif
</div>
