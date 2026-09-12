<?php

namespace App\Services\PriceList;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProfileValidator
{
    private const KEYS = ['worksheet', 'header_row', 'data_row', 'mapping', 'csv', 'empty_row_limit', 'decimal_separator', 'thousands_separator', 'date_formats', 'expiration_mode', 'shelf_life_unit', 'inventory', 'transformations', 'defaults', 'validation', 'skip_rules', 'keep_exact_duplicates', 'matching_strategy', 'activation_mode', 'automatic'];

    private const FIELDS = ['name', 'sku', 'price', 'expiration', 'manufacturer', 'country', 'batch', 'unit', 'quantity', 'total'];

    private const OPERATORS = ['trim', 'unicode_normalize', 'collapse_whitespace', 'literal_replace', 'regex_replace', 'extract', 'remove_prefix', 'lowercase', 'uppercase'];

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    public function validate(array $configuration): array
    {
        $unexpected = array_diff(array_keys($configuration), self::KEYS);
        if ($unexpected !== []) {
            throw ValidationException::withMessages(['configuration' => 'Недопустимые параметры: '.implode(', ', $unexpected)]);
        }
        $validated = Validator::make($configuration, [
            'data_row' => ['required', 'integer', 'min:1'], 'header_row' => ['nullable', 'integer', 'min:1'],
            'mapping' => ['required', 'array'], 'mapping.name' => ['required', 'string', 'regex:/^[A-Z]{1,3}$/'],
            'mapping.price' => ['required', 'string', 'regex:/^[A-Z]{1,3}$/'],
            'mapping.*' => ['nullable', 'string', 'regex:/^[A-Z]{1,3}$/'], 'date_formats' => ['sometimes', 'array'],
            'empty_row_limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'matching_strategy' => ['sometimes', 'in:name,sku,sku_then_name'], 'activation_mode' => ['sometimes', 'in:manual,automatic'],
            'transformations' => ['sometimes', 'array'], 'skip_rules' => ['sometimes', 'array'],
        ])->validate();
        if (array_diff(array_keys($validated['mapping']), self::FIELDS) !== []) {
            throw ValidationException::withMessages(['configuration.mapping' => 'Найдено неизвестное поле сопоставления.']);
        }
        foreach (($validated['transformations'] ?? []) as $field => $transformations) {
            if (! in_array($field, self::FIELDS, true) || ! is_array($transformations)) {
                throw ValidationException::withMessages(['configuration.transformations' => 'Недопустимое поле преобразования.']);
            }
            foreach ($transformations as $transformation) {
                if (! is_array($transformation) || ! in_array($transformation['type'] ?? null, self::OPERATORS, true)) {
                    throw ValidationException::withMessages(['configuration.transformations' => 'Недопустимое преобразование.']);
                }
            }
        }

        return array_replace_recursive(self::defaults(), $validated);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return ['header_row' => 1, 'data_row' => 2, 'mapping' => ['name' => 'A', 'price' => 'B'], 'csv' => ['delimiter' => ',', 'enclosure' => '"', 'encoding' => 'UTF-8'], 'empty_row_limit' => 25, 'decimal_separator' => '.', 'thousands_separator' => '', 'date_formats' => ['d.m.Y', 'd.m.y', 'Y-m-d'], 'expiration_mode' => 'date', 'transformations' => [], 'defaults' => [], 'validation' => [], 'skip_rules' => [], 'keep_exact_duplicates' => false, 'matching_strategy' => 'name', 'activation_mode' => 'manual', 'automatic' => ['minimum_valid_rows' => 1, 'maximum_error_rows' => 0, 'maximum_error_percentage' => 0, 'fatal_warning_codes' => []]];
    }
}
