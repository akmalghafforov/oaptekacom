<?php

namespace App\Http\Requests;

use App\Models\PriceListImport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmDuplicatePriceListImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $import = $this->route('import');

        return $this->user()?->can('commit', is_object($import) ? $import : PriceListImport::find($import)) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
