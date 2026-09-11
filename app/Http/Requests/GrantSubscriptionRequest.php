<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GrantSubscriptionRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'pharmacy')],
            'plan' => ['required', Rule::enum(SubscriptionPlan::class), Rule::notIn([SubscriptionPlan::Free->value])],
            'term' => ['required', Rule::enum(SubscriptionTerm::class)],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'required_if:term,'.SubscriptionTerm::Custom->value, 'after_or_equal:'.now('Asia/Dushanbe')->toDateString()],
        ];
    }
}
