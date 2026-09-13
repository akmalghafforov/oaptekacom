@props(['variant' => 'primary', 'type' => 'submit', 'href' => null])
@php($styles = ['primary' => 'bg-brand-600 text-white hover:bg-brand-700', 'secondary' => 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50', 'danger' => 'bg-danger text-white hover:bg-red-800', 'ghost' => 'text-slate-700 hover:bg-slate-100'][$variant] ?? 'bg-brand-600 text-white hover:bg-brand-700')
@if($href)
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'inline-flex min-h-10 items-center justify-center rounded-control px-4 py-2 text-sm font-semibold transition focus-visible:outline-brand-600 '.$styles]) }}>{{ $slot }}</a>
@else
<button type="{{ $type }}" {{ $attributes->merge(['class' => 'inline-flex min-h-10 items-center justify-center rounded-control px-4 py-2 text-sm font-semibold transition focus-visible:outline-brand-600 disabled:cursor-not-allowed disabled:opacity-50 '.$styles]) }}>{{ $slot }}</button>
@endif
