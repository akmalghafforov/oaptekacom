@props(['code'])

@php
    $icon = match ($code) {
        'inhalations' => 'inhaler.webp',
        'ampoules', 'suspension', 'syrup', 'solution', 'emulsion', 'liniment', 'lotion', 'oil', 'tincture', 'extract' => 'medicine-bottle.webp',
        'vials' => 'vial.webp',
        'injections', 'microenema' => 'syringe.webp',
        'suppositories' => 'pill.webp',
        'chewing_gum', 'dragee', 'lozenges' => 'pills.webp',
        'tablets', 'granules' => 'tablets.webp',
        'capsules' => 'capsules.webp',
        'ointment', 'cream', 'gel', 'paste', 'shampoo' => 'medicine.webp',
        'drops' => 'drops.webp',
        'spray', 'aerosol' => 'spray.webp',
        'powder' => 'powder.webp',
        'patch' => 'patch.webp',
        default => 'medicine.webp',
    };
@endphp

<img
    src="{{ asset('images/catalog/categories/'.$icon) }}"
    alt=""
    data-category-image
    width="32"
    height="32"
    {{ $attributes->class('size-8 object-contain') }}
>
