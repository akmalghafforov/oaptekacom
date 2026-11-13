<?php

namespace App\Http\Requests;

use App\Models\CatalogBanner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCatalogBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('whatsapp_number'))) {
            $this->merge(['whatsapp_number' => preg_replace('/[\s()+-]/', '', $this->input('whatsapp_number'))]);
        }
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:20000'],
            'whatsapp_number' => ['required', 'regex:/^[1-9][0-9]{6,14}$/'],
            'whatsapp_message' => ['nullable', 'string', 'max:2000'],
            'duration_seconds' => ['required', 'integer', 'min:2', 'max:300'],
            'is_active' => ['required', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', Rule::when($this->filled('starts_at'), ['after:starts_at'])],
        ];
        foreach (CatalogBanner::IMAGE_SIZES as $device => [$width, $height]) {
            $rules[$device.'_image'] = [$this->route('banner') ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', "dimensions:width={$width},height={$height}"];
        }

        return $rules;
    }
}
