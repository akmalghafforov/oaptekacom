<?php

namespace App\Http\Requests;

use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProvisionWholesalerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => PhoneNormalizer::normalize($this->input('phone'))]);
    }

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['organization_name' => ['required', 'string', 'max:255'], 'supplier_mode' => ['required', Rule::in(['supplier', 'buyer', 'both'])], 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users'], 'phone' => ['required', 'string', 'regex:/^\\+9929\\d{8}$/', 'unique:users']];
    }
}
