<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrganizationDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminMode() ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'pending', 'blocked'])],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('created_from'), ['after_or_equal:created_from'])],
            'sort' => ['nullable', Rule::in(['name', 'status', 'created_at', 'updated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
