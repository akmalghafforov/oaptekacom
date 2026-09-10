@props(['title', 'description' => null])
<div {{ $attributes->merge(['class' => 'mb-6 flex flex-wrap items-end justify-between gap-4']) }}><div><h1 class="text-2xl font-bold tracking-tight text-ink sm:text-3xl">{{ $title }}</h1>@if($description)<p class="mt-1 text-sm text-muted">{{ $description }}</p>@endif</div>@if(isset($actions))<div>{{ $actions }}</div>@endif</div>
