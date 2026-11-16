<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierImportProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $filter = $this->input('attachment_filename_filter');
        if (is_string($filter)) {
            $this->merge(['attachment_filename_filter' => trim($filter) === '' ? null : trim($filter)]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdminMode() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'publication_source' => ['sometimes', 'nullable', 'in:cell,filename,review'],
            'publication_cell' => ['nullable', 'required_if:publication_source,cell', 'regex:/^[A-Za-z]{1,3}[1-9][0-9]{0,6}$/'],
            'publication_pattern' => ['nullable', 'required_if:publication_source,filename', 'string', 'max:500'],
            'publication_formats' => ['nullable', 'required_with:publication_source', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'file_type' => ['required', 'in:csv,tsv,xls,xlsx'],
            'worksheet' => ['required', 'string', 'max:255'],
            'data_row' => ['required', 'integer', 'min:1', 'max:100'],
            'column_mappings' => ['required', 'array'],
            'column_mappings.*' => ['nullable', 'string', Rule::in(['ignore', 'name', 'sku', 'price', 'expiration', 'manufacturer', 'country', 'batch', 'unit', 'quantity', 'total', 'inn', 'form', 'dosage'])],
            'attachment_filename_filter' => ['nullable', 'string', 'max:255'],
            'sender_emails' => ['nullable', 'string', 'max:4000'],
            'decimal_separator' => ['required', 'string', 'max:4'],
            'matching_strategy' => ['required', 'in:name,sku,sku_then_name'],
        ];
    }
}
