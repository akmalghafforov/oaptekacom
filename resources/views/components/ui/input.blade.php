@props(['name', 'id' => null, 'label' => null, 'hint' => null, 'type' => 'text', 'value' => null, 'maskPlaceholder' => null, 'errorKey' => null])
@aware(['formKey' => null])
@php
    $restoreInput = $formKey === null || old('_promotion_form') === $formKey;
    $fieldErrors = $restoreInput ? $errors : new \Illuminate\Support\ViewErrorBag;
    $fieldError = collect($fieldErrors->get($errorKey ?? $name))->flatten()->first();
@endphp
@php($controlId = $id ?? $name)
@php($descriptionIds = collect([$hint ? $controlId.'-hint' : null, $fieldError ? $controlId.'-error' : null])->filter()->implode(' '))
<div class="space-y-1.5"><label for="{{ $controlId }}" class="block text-sm font-medium text-content">{{ $label ?? $slot }}</label><div @class(['relative' => $maskPlaceholder])>@if($maskPlaceholder)<span data-phone-mask-placeholder aria-hidden="true" class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-heading">{{ $maskPlaceholder }}</span>@endif<input id="{{ $controlId }}" name="{{ $name }}" type="{{ $type }}" value="{{ ($restoreInput && $type !== 'file' ? old($name, $value) : $value) }}" @if($descriptionIds) aria-describedby="{{ $descriptionIds }}" @endif @if($fieldError) aria-invalid="true" @endif {{ $attributes->class(['block w-full rounded-control border px-3 py-2.5 shadow-xs placeholder:text-placeholder', 'relative z-10 bg-transparent text-transparent caret-heading' => $maskPlaceholder, 'bg-surface text-heading' => ! $maskPlaceholder, 'border-danger' => $fieldError, 'border-control-border' => ! $fieldError]) }}></div>@if($hint)<p id="{{ $controlId }}-hint" class="text-xs text-muted">{{ $hint }}</p>@endif @if($fieldError)<p id="{{ $controlId }}-error" class="text-xs text-danger">{{ $fieldError }}</p>@endif</div>
