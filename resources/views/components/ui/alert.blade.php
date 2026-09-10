@props(['type' => 'success'])
@php($styles = ['success' => 'border-brand-200 bg-brand-50 text-brand-700', 'warning' => 'border-amber-200 bg-amber-50 text-warning', 'danger' => 'border-red-200 bg-red-50 text-danger'][$type] ?? 'border-brand-200 bg-brand-50 text-brand-700')
<div role="alert" {{ $attributes->merge(['class' => 'rounded-control border px-4 py-3 text-sm '.$styles]) }}>{{ $slot }}</div>
