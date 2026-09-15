@props(['label', 'href' => null, 'badge' => null, 'pressed' => null, 'disabled' => false, 'type' => 'button'])
@php
    $classes = 'relative inline-flex min-h-10 min-w-10 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-600 transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-50';
@endphp
@if($href && ! $disabled)
    <a href="{{ $href }}" aria-label="{{ $label }}" {{ $attributes->class($classes) }}>
        {{ $slot }}
        @if($badge !== null && (int) $badge > 0)<span class="absolute -right-1 -top-1 min-w-5 rounded-full bg-brand-600 px-1 text-center text-xs font-bold leading-5 text-white">{{ (int) $badge > 99 ? '99+' : $badge }}</span>@endif
    </a>
@else
    <button type="{{ $type }}" aria-label="{{ $label }}" @if($pressed !== null) aria-pressed="{{ $pressed ? 'true' : 'false' }}" @endif @disabled($disabled) {{ $attributes->class($classes) }}>
        {{ $slot }}
        @if($badge !== null && (int) $badge > 0)<span class="absolute -right-1 -top-1 min-w-5 rounded-full bg-brand-600 px-1 text-center text-xs font-bold leading-5 text-white">{{ (int) $badge > 99 ? '99+' : $badge }}</span>@endif
    </button>
@endif
