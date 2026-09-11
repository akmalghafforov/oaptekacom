<?php

namespace App\Http\Requests;

use App\Enums\TradeMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->user()->organization);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:255'], 'minimum_order' => ['nullable', 'numeric', 'min:0'], 'delivery_conditions' => ['nullable', 'string', 'max:5000'], 'active_trade_mode' => [Rule::enum(TradeMode::class)]];
    }
}
