<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierImportProfileSampleRequest extends FormRequest
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
            'sample' => [
                'required',
                'file',
                'extensions:csv,xls,xlsx',
                'mimes:csv,txt,xls,xlsx',
                'max:'.config('price-list-imports.max_file_kilobytes'),
            ],
        ];
    }
}
