@props(['name', 'title', 'description' => null, 'openOnLoad' => false])
<dialog id="{{ $name }}" data-dialog="{{ $name }}" aria-labelledby="{{ $name }}-title" @if($description) aria-describedby="{{ $name }}-description" @endif @if($openOnLoad) data-dialog-auto-open @endif {{ $attributes->class('catalog-dialog m-auto w-[min(44rem,calc(100%-2rem))] max-h-[calc(100dvh-2rem)] rounded-panel border-0 bg-white p-0 text-ink shadow-2xl backdrop:bg-slate-950/40') }}>
    <div class="flex max-h-[calc(100dvh-2rem)] flex-col">
        <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
            <div><h2 id="{{ $name }}-title" class="text-xl font-bold">{{ $title }}</h2>@if($description)<p id="{{ $name }}-description" class="mt-1 text-sm text-muted">{{ $description }}</p>@endif</div>
            <x-ui.icon-button label="Закрыть" data-dialog-close>×</x-ui.icon-button>
        </header>
        {{ $slot }}
    </div>
</dialog>
