<?php

namespace App\Http\Requests;

use App\Enums\SubscriptionStatus;
use App\Services\SubscriptionMonitoring;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IndexSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdminMode() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:100', 'regex:/[0-9]/'],
            'status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
            'ends_from' => ['nullable', 'date_format:Y-m-d'],
            'ends_to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('ends_from'), ['after_or_equal:ends_from'])],
            'expires_in' => ['nullable', Rule::in(['7', '30'])],
            'sort' => ['nullable', Rule::in(array_keys(SubscriptionMonitoring::SORT_LABELS))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.string' => 'Введите телефон текстом.',
            'phone.max' => 'Поиск по телефону: не более 100 символов.',
            'phone.regex' => 'Введите хотя бы одну цифру телефона.',
            'status.enum' => 'Выберите статус из списка.',
            'ends_from.date_format' => 'Укажите начальную дату в формате ГГГГ-ММ-ДД.',
            'ends_to.date_format' => 'Укажите конечную дату в формате ГГГГ-ММ-ДД.',
            'ends_to.after_or_equal' => 'Конечная дата должна быть не раньше начальной.',
            'expires_in.in' => 'Выберите период 7 или 30 дней.',
            'sort.in' => 'Выберите доступный столбец сортировки.',
            'direction.in' => 'Выберите направление сортировки.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $response = redirect()->route('admin.subscriptions.index')
            ->withErrors($validator)
            ->withInput(array_filter($this->only(array_keys($this->rules())), is_string(...)));

        throw new ValidationException($validator, $response);
    }
}
