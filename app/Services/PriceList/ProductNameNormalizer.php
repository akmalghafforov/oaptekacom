<?php

namespace App\Services\PriceList;

use Normalizer;

class ProductNameNormalizer
{
    /** @return array{normalized:string,tokens:list<string>,attached_forms:list<string>} */
    public function normalize(mixed $value): array
    {
        $original = trim((string) ($value ?? ''));
        $normalized = Normalizer::normalize($original, Normalizer::FORM_KC) ?: $original;
        $normalized = mb_strtolower($normalized);
        $normalized = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? $normalized;
        $stream = preg_replace('/[\\\\\/\.\-\s]+/u', ' ', $normalized) ?? $normalized;
        preg_match_all('/[\p{L}]+(?:\d+(?:[.,]\d+)?)?|\d+(?:[.,]\d+)?[\p{L}]*/u', $stream, $matches);
        $tokens = array_values(array_filter($matches[0] ?? []));
        $attached = [];
        foreach (['мазь', 'крем', 'сироп', 'гель', 'раствор', 'суспенз'] as $form) {
            if (preg_match('/'.$form.'\d/u', $normalized) === 1) {
                $attached[] = $form;
            }
        }

        return ['normalized' => $normalized, 'tokens' => $tokens, 'attached_forms' => $attached];
    }
}
