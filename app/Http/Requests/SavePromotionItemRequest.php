<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePromotionItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('promotion'));
    }

    public function rules(): array
    {
        return [
            'source_offer_id' => ['nullable', 'integer', 'exists:offers,id'],
            'name' => ['required_without:source_offer_id', 'nullable', 'string', 'max:255'],
            'dosage' => ['nullable', 'string', 'max:255'],
            'form' => ['nullable', 'string', 'max:255'],
            'packaging' => ['nullable', 'string', 'max:255'],
            'price' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'price_mode' => ['required', 'in:final,pharmacy_discount'],
            'minimum_quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:999999'],
            'in_stock' => ['required', 'boolean'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'discount_rule' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
