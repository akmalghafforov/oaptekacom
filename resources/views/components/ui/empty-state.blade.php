@props(['title', 'description' => null])
<div class="rounded-panel border border-dashed border-control-border bg-surface px-5 py-10 text-center"><p class="font-semibold text-content">{{ $title }}</p>@if($description)<p class="mt-1 text-sm text-muted">{{ $description }}</p>@endif @if(isset($action))<div class="mt-4">{{ $action }}</div>@endif</div>
