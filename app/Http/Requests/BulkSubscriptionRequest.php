<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionTerm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminMode() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('role', 'pharmacy')],
            'action' => ['required', Rule::in(['set', 'extend'])],
            'plan' => ['required', Rule::enum(SubscriptionPlan::class), Rule::notIn([SubscriptionPlan::Free->value])],
            'term' => ['required', Rule::enum(SubscriptionTerm::class)],
            'ends_on' => ['exclude_unless:term,custom', 'required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Dushanbe')->toDateString()],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['user_ids' => 'аккаунты аптек', 'user_ids.*' => 'аккаунт аптеки', 'action' => 'действие', 'plan' => 'тариф', 'term' => 'срок', 'ends_on' => 'дата окончания'];
    }
}
