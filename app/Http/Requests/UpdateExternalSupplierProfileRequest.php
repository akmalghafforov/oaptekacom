<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateExternalSupplierProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminMode() ?? false;
    }

    public function rules(): array
    {
        return [
            'expected_supplier_organization_id' => ['required', 'integer', 'min:1'],
            'expected_profile_version' => ['required', 'integer', 'min:1'],
            'expected_source' => ['required', 'in:icorp,pricelist_tj'],
            'expected_external_id' => ['required', 'string', 'max:255'],
            'profile_active' => ['required', 'boolean'], 'matching_strategy' => ['required', 'in:name,sku,sku_then_name'],
            'date_formats' => ['required', 'string', 'max:500'], 'minimum_valid_rows' => ['required', 'integer', 'min:1'],
            'maximum_error_rows' => ['required', 'integer', 'min:0'], 'maximum_error_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Заполните это поле.', 'integer' => 'Введите целое число.',
            'numeric' => 'Введите число.', 'boolean' => 'Выберите состояние.',
            'min' => 'Допустимое минимальное значение: :min.', 'max' => 'Допустимое максимальное значение: :max.',
            'in' => 'Выберите допустимое значение.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [...array_keys($this->rules()), '_token', '_method', '_mode_version', '_sync_form'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'Недопустимый параметр. Обновите профиль импорта.');
            }
            if (is_string($this->input('date_formats')) && array_filter(array_map('trim', explode(',', $this->input('date_formats')))) === []) {
                $validator->errors()->add('date_formats', 'Укажите хотя бы один формат даты.');
            }
        }];
    }
}
