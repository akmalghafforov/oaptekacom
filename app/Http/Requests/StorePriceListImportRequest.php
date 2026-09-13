<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePriceListImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || ($this->user()?->canSupply() && $this->user()?->organization?->status === 'active');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['supplier_organization_id' => ['nullable', 'integer', 'exists:organizations,id'], 'file' => ['required', 'file', 'extensions:csv,tsv,xls,xlsx', 'mimes:csv,txt,xls,xlsx', 'max:'.config('price-list-imports.max_file_kilobytes')]];
    }
}
