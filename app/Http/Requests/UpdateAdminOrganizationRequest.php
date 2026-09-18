<?php

namespace App\Http\Requests;

use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminOrganizationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('phone')) {
            $this->merge(['phone' => PhoneNormalizer::normalize($this->string('phone')->toString())]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return [
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'regex:/^\\+992\\d{9}$/', Rule::unique('organizations')->where('type', $organization->type->value)->ignore($organization)],
            'status' => ['required', Rule::in(['active', 'blocked'])],
            'supplier_mode' => [Rule::requiredIf($organization->type === OrganizationType::Wholesaler), 'nullable', Rule::in(['supplier', 'both'])],
            'minimum_order' => [Rule::requiredIf($organization->type === OrganizationType::Wholesaler), 'nullable', 'numeric', 'min:0'],
            'delivery_conditions' => ['nullable', 'string', 'max:5000'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'whatsapp_phone' => ['nullable', 'string', 'max:30'],
            'additional_phones' => ['nullable', 'string', 'max:500'],
        ];
    }
}
