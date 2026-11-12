<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSupplierSynchronizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminMode() ?? false;
    }

    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:0'],
            'selection_mode' => ['nullable', 'in:newest_available'],
            'selected_source' => ['nullable', 'required_unless:selection_mode,newest_available', 'in:email,icorp,pricelist_tj'],
            'automatic_enabled' => ['required', 'boolean'], 'paused' => ['required', 'boolean'],
            'fetch_interval_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'freshness_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'sources' => ['required', 'array:email,icorp,pricelist_tj', 'size:3'],
            'sources.*' => ['required', 'array:enabled,priority'],
            'sources.*.enabled' => ['sometimes', 'boolean'],
            'sources.*.priority' => ['required', 'integer', 'min:1', 'max:3', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'selected_source.required_unless' => 'Выберите один источник в профиле импорта. Резервная загрузка больше не поддерживается.',
            'selected_source.required' => 'Выберите один источник в профиле импорта. Резервная загрузка больше не поддерживается.',
            'expected_version.required' => 'Обновите профиль импорта перед сохранением настроек.',
            'required' => 'Заполните это поле.', 'boolean' => 'Выберите состояние.',
            'in' => 'Выберите допустимый источник.',
            'integer' => 'Введите целое число.', 'min' => 'Допустимое минимальное значение: :min.',
            'max' => 'Допустимое максимальное значение: :max.',
            'distinct' => 'Каждому источнику нужен отдельный справочный приоритет.',
            'array' => 'Проверьте настройки источников.', 'size' => 'Настройте все три источника.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [...array_filter(array_keys($this->rules()), fn (string $field): bool => ! str_contains($field, '.')), '_token', '_method', '_mode_version', '_sync_form'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'Недопустимый параметр. Обновите профиль импорта.');
            }
            if ($this->input('selection_mode') === 'newest_available' && ! collect($this->input('sources', []))->contains(fn ($source): bool => (bool) ($source['enabled'] ?? false))) {
                $validator->errors()->add('sources', 'Включите хотя бы один источник.');
            }
            if ($this->input('selection_mode') !== 'newest_available' && $validator->errors()->isEmpty() && collect($this->input('sources'))->filter(fn (array $source): bool => (bool) ($source['enabled'] ?? false))->count() > 1) {
                $validator->errors()->add('sources', 'Выберите один источник в профиле импорта. Резервная загрузка больше не поддерживается.');
            }
        }];
    }
}
