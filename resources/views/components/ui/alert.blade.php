@props(['type' => 'success'])
@php($styles = ['success' => 'border-success-border bg-accent-surface text-accent', 'warning' => 'border-warning-border bg-warning-surface text-warning', 'danger' => 'border-danger-border bg-danger-surface text-danger'][$type] ?? 'border-success-border bg-accent-surface text-accent')
<div role="alert" {{ $attributes->merge(['class' => 'rounded-control border px-4 py-3 text-sm '.$styles]) }}>{{ $slot }}</div>
