@if($paginator->hasPages())
<nav aria-label="{{ __('Pagination Navigation') }}">
    <div class="flex items-center justify-between gap-2 sm:hidden">
        @if($paginator->onFirstPage())
            <x-ui.button variant="secondary" disabled>{!! __('pagination.previous') !!}</x-ui.button>
        @else
            <x-ui.button variant="secondary" :href="$paginator->previousPageUrl()" rel="prev">{!! __('pagination.previous') !!}</x-ui.button>
        @endif
        @if($paginator->hasMorePages())
            <x-ui.button variant="secondary" :href="$paginator->nextPageUrl()" rel="next">{!! __('pagination.next') !!}</x-ui.button>
        @else
            <x-ui.button variant="secondary" disabled>{!! __('pagination.next') !!}</x-ui.button>
        @endif
    </div>
    <div class="hidden flex-wrap items-center justify-between gap-3 sm:flex">
        <p class="text-sm text-content">
            {{ __('Showing') }} <span class="font-medium">{{ $paginator->firstItem() ?? $paginator->count() }}</span>
            {{ __('to') }} <span class="font-medium">{{ $paginator->lastItem() }}</span>
            {{ __('of') }} <span class="font-medium">{{ $paginator->total() }}</span> {{ __('results') }}
        </p>
        <div class="flex flex-wrap items-center gap-1">
            <x-ui.icon-button :label="__('pagination.previous')" :href="$paginator->previousPageUrl()" :disabled="$paginator->onFirstPage()" rel="prev"><span aria-hidden="true">‹</span></x-ui.icon-button>
            @foreach($elements as $element)
                @if(is_string($element))
                    <span class="grid min-h-10 min-w-10 place-items-center text-muted" aria-hidden="true">{{ $element }}</span>
                @else
                    @foreach($element as $page => $url)
                        @if($page == $paginator->currentPage())
                            <span aria-current="page" class="grid min-h-10 min-w-10 place-items-center rounded-control border border-accent-strong bg-accent-surface px-3 text-sm font-semibold text-accent">{{ $page }}</span>
                        @else
                            <x-ui.button variant="secondary" :href="$url" :aria-label="__('Go to page :page', ['page' => $page])" class="min-w-10">{{ $page }}</x-ui.button>
                        @endif
                    @endforeach
                @endif
            @endforeach
            <x-ui.icon-button :label="__('pagination.next')" :href="$paginator->nextPageUrl()" :disabled="! $paginator->hasMorePages()" rel="next"><span aria-hidden="true">›</span></x-ui.icon-button>
        </div>
    </div>
</nav>
@endif
