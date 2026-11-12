<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SupplierDirectoryContext
{
    /** @return array<string, list<string>> */
    public static function fields(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'supplier_q' => ['nullable', 'string', 'max:255'],
            'tab' => ['nullable', 'in:external,suppliers'],
            'service' => ['nullable', 'in:icorp,pricelist_tj'],
            'edit' => ['nullable', 'integer', 'min:1'],
            'filter' => ['nullable', 'in:all,unmapped,ambiguous,confirmed,suggested'],
            'page' => ['nullable', 'integer', 'min:1'],
            'supplier_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        $rules = ['list_context' => ['sometimes', 'array:'.implode(',', array_keys(self::fields()))]];
        foreach (self::fields() as $field => $fieldRules) {
            $rules['list_context.'.$field] = $fieldRules;
        }

        return $rules;
    }

    /** @return array<string, mixed>|null */
    public static function valid(Request $request): ?array
    {
        $validator = Validator::make($request->only('list_context'), self::rules());

        return $validator->fails() ? null : ($validator->validated()['list_context'] ?? null);
    }
}
