<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'methods' => ['required', 'array'],
            'methods.*.method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'methods.*.is_enabled' => ['nullable', 'boolean'],
            'methods.*.wallet_number' => ['nullable', 'string', 'regex:/^\\+992\\d{9}$/'],
            'methods.*.wallet_owner_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
