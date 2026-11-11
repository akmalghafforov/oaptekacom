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
            'automatic_enabled' => ['required', 'boolean'], 'paused' => ['required', 'boolean'],
            'fetch_interval_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'freshness_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'sources' => ['required', 'array:email,icorp,pricelist_tj', 'size:3'],
            'sources.*' => ['required', 'array:enabled,priority'],
            'sources.*.enabled' => ['required', 'boolean'],
            'sources.*.priority' => ['required', 'integer', 'min:1', 'max:3', 'distinct'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Заполните это поле.', 'boolean' => 'Выберите состояние.',
            'integer' => 'Введите целое число.', 'min' => 'Допустимое минимальное значение: :min.',
            'max' => 'Допустимое максимальное значение: :max.',
            'distinct' => 'Каждому источнику нужен отдельный приоритет.',
            'array' => 'Проверьте настройки источников.', 'size' => 'Настройте все три источника.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->isEmpty()) {
                return;
            }
            if (! collect($this->input('sources'))->contains(fn (array $source): bool => (bool) $source['enabled'])) {
                $validator->errors()->add('sources', 'Включите хотя бы один источник.');
            }
        }];
    }
}
