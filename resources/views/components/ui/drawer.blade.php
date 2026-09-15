@props(['name', 'title'])

<dialog id="{{ $name }}" data-drawer aria-labelledby="{{ $name }}-title" {{ $attributes->class('account-drawer fixed inset-0 m-0 ml-auto h-dvh max-h-none w-full max-w-md border-0 bg-transparent p-0 text-ink backdrop:bg-slate-950/40') }}>
    <div data-drawer-panel class="flex h-dvh flex-col bg-white shadow-2xl">
        <header class="flex min-h-18 items-center justify-between gap-4 border-b border-slate-200 px-5 py-4">
            <h2 id="{{ $name }}-title" class="text-lg font-bold">{{ $title }}</h2>
            <x-ui.icon-button label="Закрыть меню" data-drawer-close>
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 6l12 12M18 6 6 18" /></svg>
            </x-ui.icon-button>
        </header>
        {{ $slot }}
    </div>
</dialog>
