<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CatalogSearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:3', 'max:200'],
            'category' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('is_active', true)],
            'city' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
            'form' => ['nullable', 'string', 'max:100'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'cursor' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('cursor')) {
                return;
            }

            if (! is_string($this->input('cursor'))) {
                return;
            }

            $cursor = Cursor::fromEncoded($this->input('cursor'));
            $parameters = $cursor?->toArray() ?? [];
            $price = $parameters['price'] ?? $parameters['offers.price'] ?? null;
            $id = $parameters['id'] ?? $parameters['offers.id'] ?? null;

            if (! is_numeric($price) || ! is_numeric($id)) {
                $validator->errors()->add('cursor', 'Некорректный курсор страницы.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['q', 'city', 'form'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }

        $this->merge($normalized);
    }

    public function messages(): array
    {
        return [
            'q.required' => 'Введите запрос для поиска.',
            'q.min' => 'Введите минимум 3 символа.',
            'max_price.gte' => 'Максимальная цена должна быть не меньше минимальной.',
        ];
    }
}
