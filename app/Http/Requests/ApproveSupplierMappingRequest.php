<?php

namespace App\Http\Requests;

use App\Enums\OrganizationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveSupplierMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminMode() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('review_snapshot'))) {
            $snapshot = json_decode($this->input('review_snapshot'), true);
            if (is_array($snapshot)) {
                $this->merge(array_intersect_key($snapshot, array_flip(['partner_versions', 'partner_evidence', 'supplier_versions', 'supplier_evidence'])));
            }
        }
        if (is_array($this->input('partner_ids'))) {
            $this->merge(['partner_ids' => array_filter($this->input('partner_ids'), fn (mixed $id): bool => $id !== null && $id !== '')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'review_snapshot' => ['sometimes', 'string', 'json', 'max:2097152'],
            'partner_ids' => ['required', 'array', 'min:1', 'max:2'],
            'partner_ids.*' => ['required', 'integer', 'distinct', 'exists:external_supplier_partners,id'],
            'partner_versions' => ['required', 'array'],
            'partner_versions.*' => ['required', 'integer', 'min:1'],
            'partner_evidence' => ['required', 'array'],
            'partner_evidence.*' => ['required', 'string', 'size:64'],
            'mapping_mode' => ['required', 'in:existing,new'],
            'supplier_organization_id' => ['exclude_unless:mapping_mode,existing', 'required', 'integer', Rule::exists('organizations', 'id')->where('type', OrganizationType::Wholesaler->value)],
            'supplier_versions' => ['required_if:mapping_mode,existing', 'array'],
            'supplier_versions.*' => ['required', 'integer', 'min:0'],
            'supplier_evidence' => ['required_if:mapping_mode,existing', 'array'],
            'supplier_evidence.*' => ['required', 'string', 'size:64'],
            'name' => ['exclude_unless:mapping_mode,new', 'required', 'string', 'max:255'],
            'city' => ['exclude_unless:mapping_mode,new', 'nullable', 'string', 'max:255'],
            'phone' => ['exclude_unless:mapping_mode,new', 'nullable', 'string', 'max:32', 'unique:organizations,phone'],
            'confirmed' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['required' => 'Заполните это поле.', 'required_if' => 'Обновите страницу и повторите проверку.',
            'accepted' => 'Подтвердите соответствие выбранных записей.', 'in' => 'Выберите допустимое значение.',
            'exists' => 'Запись больше не существует. Обновите страницу.', 'distinct' => 'Выберите разные записи.',
            'array' => 'Проверьте выбранные записи.', 'integer' => 'Выберите запись из списка.',
            'string' => 'Введите текст.', 'max' => 'Допустимое максимальное значение: :max.',
            'min' => 'Выберите хотя бы одну запись.', 'size' => 'Обновите страницу и повторите проверку.',
            'unique' => 'Этот телефон уже используется.'];
    }
}
