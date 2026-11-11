@props(['name', 'id' => null, 'label' => null, 'errorKey' => null, 'restore' => null, 'hint' => null])
@aware(['formKey' => null])
@php
    $restoreInput = $restore ?? ($formKey === null || old('_promotion_form') === $formKey);
    $fieldErrors = $restoreInput ? $errors : new \Illuminate\Support\ViewErrorBag;
@endphp
@php($errorKey = $errorKey ?? $name)
@php($controlId = $id ?? $name)
@php($descriptionIds = collect([$hint ? $controlId.'-hint' : null, $fieldErrors->has($errorKey) ? $controlId.'-error' : null])->filter()->implode(' '))
<div class="space-y-1.5"><label for="{{ $controlId }}" class="block text-sm font-medium text-content">{{ $label ?? $slot }}</label><select id="{{ $controlId }}" name="{{ $name }}" @if($descriptionIds) aria-describedby="{{ $descriptionIds }}" @endif @if($fieldErrors->has($errorKey)) aria-invalid="true" @endif {{ $attributes->class(['block w-full rounded-control border bg-surface px-3 py-2.5 text-heading', 'border-danger' => $fieldErrors->has($errorKey), 'border-control-border' => ! $fieldErrors->has($errorKey)]) }}>{{ $slot }}</select>@if($hint)<p id="{{ $controlId }}-hint" class="text-xs text-muted">{{ $hint }}</p>@endif @if($fieldErrors->has($errorKey))<p id="{{ $controlId }}-error" class="text-xs text-danger">{{ $fieldErrors->first($errorKey) }}</p>@endif</div>
