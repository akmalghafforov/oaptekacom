<?php

namespace App\Http\Requests;

use App\Models\Promotion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('promotion')
            ? $this->user()->can('update', $this->route('promotion'))
            : $this->user()->can('create', Promotion::class);
    }

    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'conditions' => ['nullable', 'string', 'max:20000'],
            'city' => ['nullable', 'string', Rule::in(array_filter([...config('registration.cities'), $this->route('promotion')?->city]))],
            'poster' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ];
    }
}
