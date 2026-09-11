<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionTerm;
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
            'term' => ['required', Rule::enum(SubscriptionTerm::class), Rule::notIn([SubscriptionTerm::Custom->value])],
        ];
    }
}
