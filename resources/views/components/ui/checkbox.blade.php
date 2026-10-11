@props(['name', 'id' => null, 'label' => null, 'hint' => null, 'value' => '1', 'checked' => false])
@php($controlId = $id ?? $name)
@php($descriptionIds = collect([$hint ? $controlId.'-hint' : null, $errors->has($name) ? $controlId.'-error' : null])->filter()->implode(' '))
<div class="space-y-1.5">
    <label for="{{ $controlId }}" class="flex min-h-10 cursor-pointer items-center gap-2 text-sm font-medium text-slate-700">
        <input id="{{ $controlId }}" name="{{ $name }}" type="checkbox" value="{{ $value }}" @checked(old($name, $checked)) @if($descriptionIds) aria-describedby="{{ $descriptionIds }}" @endif @if($errors->has($name)) aria-invalid="true" @endif {{ $attributes->class(['size-4 shrink-0 rounded border-slate-300 accent-brand-600']) }}>
        <span>{{ $label ?? $slot }}</span>
    </label>
    @if($hint)<p id="{{ $controlId }}-hint" class="text-xs text-muted">{{ $hint }}</p>@endif
    @error($name)<p id="{{ $controlId }}-error" class="text-xs text-danger">{{ $message }}</p>@enderror
</div>
