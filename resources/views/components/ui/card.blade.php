@props(['class' => ''])
<section {{ $attributes->merge(['class' => 'rounded-panel border border-slate-200 bg-surface p-4 shadow-panel sm:p-5 '.$class]) }}>{{ $slot }}</section>
