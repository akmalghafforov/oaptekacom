<?php

namespace App\Http\Requests;

use App\Enums\PromotionConditionMatch;
use App\Enums\PromotionPurchaseScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SavePromotionParticipationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('promotion'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['_promotion_form' => 'participation']);
        if (is_array($this->input('prizes'))) {
            $this->merge(['prizes' => array_values(array_filter($this->input('prizes'), fn ($prize): bool => ! is_array($prize)
                || filled($prize['name'] ?? null) || filled($prize['description'] ?? null) || (filled($prize['quantity'] ?? null) && (string) $prize['quantity'] !== '1')))]);
        }
    }

    public function rules(): array
    {
        if ($this->has('add_prize')) {
            return ['add_prize' => ['required', 'in:1'], 'prize_rows' => ['required', 'integer', 'min:1', 'max:99']];
        }

        return [
            'participation_enabled' => ['required', 'boolean'],
            'purchase_scope' => ['exclude_unless:participation_enabled,1', 'required', Rule::enum(PromotionPurchaseScope::class)],
            'condition_match' => ['exclude_unless:participation_enabled,1', 'required', Rule::enum(PromotionConditionMatch::class)],
            'minimum_total' => ['exclude_unless:participation_enabled,1', 'nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999999999.99'],
            'minimum_order_value' => ['exclude_unless:participation_enabled,1', 'nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999999999.99'],
            'minimum_order_count' => ['exclude_unless:participation_enabled,1', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'prizes' => ['exclude_unless:participation_enabled,1', 'required', 'array', 'min:1', 'max:100'],
            'prizes.*' => ['array:name,description,quantity'],
            'prizes.*.name' => ['required', 'string', 'max:255'],
            'prizes.*.description' => ['nullable', 'string', 'max:5000'],
            'prizes.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Заполните поле :attribute.',
            'boolean' => 'Выберите, включено ли участие.',
            'numeric' => 'Введите сумму числом.',
            'decimal' => 'Укажите сумму с точностью до двух знаков после запятой.',
            'integer' => 'Введите целое число.',
            'min' => 'Значение поля :attribute должно быть не меньше :min.',
            'max' => 'Значение поля :attribute должно быть не больше :max.',
            'string' => 'Введите текст.',
            'prizes.*.name.max' => 'Допускается не более :max символов.',
            'prizes.*.description.max' => 'Допускается не более :max символов.',
            'array' => 'Проверьте список призов.',
            'prizes.required' => 'Добавьте хотя бы один приз с названием.',
            'prizes.min' => 'Добавьте хотя бы один приз с названием.',
            'prizes.max' => 'Допускается не более 100 призов.',
            'purchase_scope.enum' => 'Выберите, какие покупки учитывать.',
            'condition_match.enum' => 'Выберите способ выполнения условий.',
        ];
    }

    public function attributes(): array
    {
        return [
            'purchase_scope' => '«Какие покупки учитывать»', 'condition_match' => '«Выполнение условий»',
            'minimum_total' => '«Сумма всех покупок»', 'minimum_order_value' => '«Сумма одного заказа»',
            'minimum_order_count' => '«Количество заказов»', 'prizes.*.name' => '«Название приза»',
            'prizes.*.quantity' => '«Количество призов»',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->has('add_prize') && $this->boolean('participation_enabled') && ! $this->filled('minimum_total') && ! $this->filled('minimum_order_value') && ! $this->filled('minimum_order_count')) {
                $validator->errors()->add('minimum_total', 'Укажите хотя бы одно условие участия.');
            }
        }];
    }
}
