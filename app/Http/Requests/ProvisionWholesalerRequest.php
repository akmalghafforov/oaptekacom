<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProvisionWholesalerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['organization_name' => ['required', 'string', 'max:255'], 'supplier_mode' => ['required', Rule::in(['supplier', 'buyer', 'both'])], 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users'], 'phone' => ['required', 'string', 'max:30', 'unique:users']];
    }
}
