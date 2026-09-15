@props(['code'])
<svg {{ $attributes->class('size-8') }} viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
    @if(in_array($code, ['tablets', 'capsules', 'dragee'], true))
        <path d="M8 23 23 8a5 5 0 0 1 7 7L15 30a5 5 0 0 1-7-7Z"/><path d="m12 19 7 7"/>
    @elseif(in_array($code, ['injections', 'ampoules', 'vials'], true))
        <path d="M12 4h8M14 4v5l-3 4v14h10V13l-3-4V4M11 17h10"/>
    @elseif($code === 'suppositories')
        <path d="M16 3c5 6 8 10 8 16a8 8 0 1 1-16 0c0-6 3-10 8-16Z"/>
    @else
        <path d="M10 4h12v5l3 5v13H7V14l3-5V4Z"/><path d="M7 17h18"/>
    @endif
</svg>
