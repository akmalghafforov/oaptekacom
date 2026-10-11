@props(['variant' => 'primary', 'type' => 'submit', 'href' => null])
@php($styles = ['primary' => 'bg-action text-white hover:bg-action-hover', 'secondary' => 'border border-control-border bg-surface text-content hover:bg-surface-subtle', 'danger' => 'bg-danger-action text-white hover:bg-danger-action-hover', 'ghost' => 'text-content hover:bg-surface-hover'][$variant] ?? 'bg-action text-white hover:bg-action-hover')
@if($href)
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'inline-flex min-h-10 items-center justify-center rounded-control px-4 py-2 text-sm font-semibold transition focus-visible:outline-focus '.$styles]) }}>{{ $slot }}</a>
@else
<button type="{{ $type }}" {{ $attributes->merge(['class' => 'inline-flex min-h-10 items-center justify-center rounded-control px-4 py-2 text-sm font-semibold transition focus-visible:outline-focus disabled:cursor-not-allowed disabled:opacity-50 '.$styles]) }}>{{ $slot }}</button>
@endif
