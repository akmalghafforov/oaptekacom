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
            'q' => ['nullable', 'string', 'min:3', 'max:200'],
            'category' => ['nullable', 'integer', Rule::exists('product_categories', 'id')->where('is_active', true)],
            'city' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'integer', Rule::exists('organizations', 'id')],
            'cities' => ['nullable', 'array', 'max:50'],
            'cities.*' => ['string', 'max:100'],
            'suppliers' => ['nullable', 'array', 'max:100'],
            'suppliers.*' => ['integer', 'distinct', Rule::exists('organizations', 'id')],
            'sort' => ['nullable', Rule::in(array_keys(config('catalog.sorts')))],
            'view' => ['nullable', Rule::in(['list', 'grid', 'suppliers'])],
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
            $id = $parameters['id'] ?? $parameters['offers.id'] ?? null;
            $sort = $this->input('sort', 'price_asc');
            $sortKeys = match ($sort) {
                'name_asc' => ['normalized_name', 'id'],
                'updated_desc' => ['updated_at', 'id'],
                default => ['effective_price', 'id'],
            };

            if (! is_numeric($id) || collect($sortKeys)->contains(fn (string $key): bool => ! array_key_exists($key, $parameters) && ! array_key_exists('offers.'.$key, $parameters))) {
                $validator->errors()->add('cursor', 'Некорректный курсор страницы.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['q', 'city', 'form', 'sort', 'view'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }

        $this->merge($normalized);

        if (! $this->has('cities') && $this->filled('city')) {
            $this->merge(['cities' => [$this->input('city')]]);
        }

        if (! $this->has('suppliers') && $this->filled('supplier')) {
            $this->merge(['suppliers' => [(int) $this->input('supplier')]]);
        }
    }

    public function messages(): array
    {
        return [
            'q.min' => 'Введите минимум 3 символа.',
            'max_price.gte' => 'Максимальная цена должна быть не меньше минимальной.',
        ];
    }
}
