<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', Rule::unique('users')->ignore($this->user()->id)], 'phone' => ['nullable', 'string', 'max:30', Rule::unique('users')->ignore($this->user()->id)], 'theme' => ['required', Rule::in(['light', 'dark'])], 'password' => ['nullable', 'confirmed', 'min:8']];
    }
}
