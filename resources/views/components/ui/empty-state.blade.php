@props(['title', 'description' => null])
<div class="rounded-panel border border-dashed border-slate-300 bg-white px-5 py-10 text-center"><p class="font-semibold text-slate-700">{{ $title }}</p>@if($description)<p class="mt-1 text-sm text-muted">{{ $description }}</p>@endif @if(isset($action))<div class="mt-4">{{ $action }}</div>@endif</div>
