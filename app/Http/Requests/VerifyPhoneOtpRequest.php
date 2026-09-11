<?php

namespace App\Http\Requests;

use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class VerifyPhoneOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => PhoneNormalizer::normalize($this->input('phone'))]);
    }

    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'regex:/^\\+992\\d{9}$/'], 'code' => ['required', 'digits:6']];
    }
}
