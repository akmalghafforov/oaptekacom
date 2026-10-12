<?php

namespace App\Http\Requests;

use App\Enums\AccountMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return config('account-modes.enabled') && $this->user() !== null;
    }

    public function rules(): array
    {
        return ['mode' => ['required', Rule::enum(AccountMode::class)], '_mode_version' => ['required', 'string']];
    }
}
