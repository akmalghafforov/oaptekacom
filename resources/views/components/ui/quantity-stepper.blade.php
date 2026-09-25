<div>
    <!-- Let all your things have their places; let each part of your business have its time. - Benjamin Franklin -->
</div>
@props(['name', 'value', 'max' => 999, 'id' => null])
@php($inputId = $id ?? $name)
<div class="flex items-end gap-2" data-quantity-stepper>
    <button type="button" class="grid min-h-10 min-w-10 place-items-center rounded-control border border-slate-300 bg-white text-lg font-semibold text-slate-700 hover:bg-slate-50" aria-label="Уменьшить количество" data-quantity-step="-1">−</button>
    <x-ui.input :id="$inputId" :name="$name" type="number" label="Количество" :value="$value" min="1" :max="$max" class="w-24 text-center" data-quantity-input />
    <button type="button" class="grid min-h-10 min-w-10 place-items-center rounded-control border border-slate-300 bg-white text-lg font-semibold text-slate-700 hover:bg-slate-50" aria-label="Увеличить количество" data-quantity-step="1">+</button>
</div>
