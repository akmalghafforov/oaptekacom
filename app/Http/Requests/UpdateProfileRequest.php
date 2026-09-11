<?php

namespace App\Http\Requests;

use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->user()?->isAdmin() && $this->filled('phone')) {
            $this->merge(['phone' => PhoneNormalizer::normalize($this->input('phone'))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        if (! $this->user()->isAdmin()) {
            return ['name' => ['required', 'string', 'max:255'], 'theme' => ['required', Rule::in(['light', 'dark'])]];
        }

        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', Rule::unique('users')->ignore($this->user()->id)], 'phone' => ['nullable', 'string', 'regex:/^\\+992\\d{9}$/', Rule::unique('users')->ignore($this->user()->id)], 'theme' => ['required', Rule::in(['light', 'dark'])], 'password' => ['nullable', 'confirmed', 'min:8']];
    }
}
