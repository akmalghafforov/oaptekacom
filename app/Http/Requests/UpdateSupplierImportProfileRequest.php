<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierImportProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'file_type' => ['required', 'in:csv,tsv,xls,xlsx'],
            'worksheet' => ['required', 'string', 'max:255'],
            'data_row' => ['required', 'integer', 'min:1', 'max:100'],
            'column_mappings' => ['required', 'array'],
            'column_mappings.*' => ['nullable', 'string', Rule::in(['ignore', 'name', 'sku', 'price', 'expiration', 'manufacturer', 'country', 'batch', 'unit', 'quantity', 'total', 'inn', 'form', 'dosage'])],
            'sender_emails' => ['nullable', 'string', 'max:4000'],
            'decimal_separator' => ['required', 'string', 'max:4'],
            'matching_strategy' => ['required', 'in:name,sku,sku_then_name'],
        ];
    }
}
