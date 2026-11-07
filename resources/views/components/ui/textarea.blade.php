@props(['name', 'id' => null, 'label', 'hint' => null, 'value' => null])
@aware(['formKey' => null])
@php
    $restoreInput = $formKey === null || old('_promotion_form') === $formKey;
    $fieldErrors = $restoreInput ? $errors : new \Illuminate\Support\ViewErrorBag;
@endphp
@php($controlId = $id ?? $name)
@php($descriptionIds = collect([$hint ? $controlId.'-hint' : null, $fieldErrors->has($name) ? $controlId.'-error' : null])->filter()->implode(' '))
<div class="space-y-1.5"><label for="{{ $controlId }}" class="block text-sm font-medium text-content">{{ $label }}</label><textarea id="{{ $controlId }}" name="{{ $name }}" @if($descriptionIds) aria-describedby="{{ $descriptionIds }}" @endif @if($fieldErrors->has($name)) aria-invalid="true" @endif {{ $attributes->class(['block min-h-24 w-full rounded-control border bg-surface px-3 py-2.5 text-heading', 'border-danger' => $fieldErrors->has($name), 'border-control-border' => ! $fieldErrors->has($name)]) }}>{{ ($restoreInput ? old($name, $value) : $value) }}</textarea>@if($hint)<p id="{{ $controlId }}-hint" class="text-xs text-muted">{{ $hint }}</p>@endif @if($fieldErrors->has($name))<p id="{{ $controlId }}-error" class="text-xs text-danger">{{ $fieldErrors->first($name) }}</p>@endif</div>
