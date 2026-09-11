<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSubscriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->isCustomer() ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'plan' => ['required', Rule::enum(SubscriptionPlan::class), Rule::notIn([SubscriptionPlan::Free->value])],
            'amount' => ['required', 'decimal:0,2', 'min:0.01'],
            'payment_method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'sender_wallet_number' => ['nullable', 'required_without:receipt', 'string', 'regex:/^\\+992\\d{9}$/'],
            'sender_wallet_owner_name' => ['nullable', 'string', 'max:255'],
            'transferred_on' => ['required', 'date_format:d/m/Y', 'before_or_equal:today'],
            'receipt' => ['nullable', 'required_without:sender_wallet_number', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ];
    }
}
