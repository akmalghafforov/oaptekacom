<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StorePharmacyRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->session()->has('phone_otp.registration.phone'), 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['pharmacy_name', 'city'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Str::trim($this->input($field))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pharmacy_name' => ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                if (! preg_match('/^[\p{L}\p{Nd} ]+$/u', $value)) {
                    $fail('Название аптеки может содержать только буквы, цифры и пробелы.');
                } elseif (! preg_match('/\p{L}/u', $value)) {
                    $fail('Название аптеки должно содержать хотя бы одну букву.');
                }
            }],
            'city' => ['bail', 'required', 'string', Rule::in(config('registration.cities'))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'pharmacy_name.required' => 'Укажите название аптеки.',
            'pharmacy_name.string' => 'Название аптеки должно быть строкой.',
            'pharmacy_name.max' => 'Название аптеки не должно превышать 255 символов.',
            'city.required' => 'Выберите город аптеки.',
            'city.string' => 'Выберите город аптеки из списка.',
            'city.in' => 'Выберите город аптеки из списка.',
        ];
    }
}
