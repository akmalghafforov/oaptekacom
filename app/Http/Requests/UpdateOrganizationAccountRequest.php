<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationAccountRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'phone' => ['nullable', 'regex:/^\\+992\\d{9}$/', Rule::unique('users')->where('role', $user->role->value)->ignore($user)],
            'is_blocked' => ['required', 'boolean'],
        ];
    }
}
