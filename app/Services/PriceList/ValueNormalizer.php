<?php

namespace App\Services\PriceList;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Normalizer;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ValueNormalizer
{
    public function name(mixed $value): string
    {
        $value = $this->text($value);
        $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        $value = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s*-\s*/u', '-', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    public function sku(mixed $value): ?string
    {
        $value = mb_strtolower(preg_replace('/\s+/u', '', $this->text($value)) ?? '');

        return $value === '' ? null : $value;
    }

    public function text(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) ($value ?? ''));
    }

    public function decimal(mixed $value, string $decimalSeparator = '.', string $thousandsSeparator = ''): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $number = str_replace(["\xc2\xa0", ' '], '', trim((string) $value));
        if ($thousandsSeparator !== '') {
            $number = str_replace($thousandsSeparator, '', $number);
        }
        if ($decimalSeparator !== '.') {
            $number = str_replace($decimalSeparator, '.', $number);
        }

        return is_numeric($number) ? (float) $number : null;
    }

    /** @param list<string> $formats */
    public function date(mixed $value, array $formats, string $timezone = 'Asia/Dushanbe'): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone($timezone)->startOfDay();
        }
        if (is_numeric($value) && (float) $value > 0) {
            return CarbonImmutable::instance(Date::excelToDateTimeObject((float) $value))->setTimezone($timezone)->startOfDay();
        }
        $text = trim((string) $value);
        foreach ($formats as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $text, $timezone);
                if ($date !== false && $date->format($format) === $text) {
                    return $date;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $transformations */
    public function transform(mixed $value, array $transformations): mixed
    {
        foreach ($transformations as $transformation) {
            $type = $transformation['type'];
            $text = $this->text($value);
            $value = match ($type) {
                'trim' => trim($text),
                'unicode_normalize' => Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text,
                'collapse_whitespace' => preg_replace('/\s+/u', ' ', $text) ?? $text,
                'literal_replace' => str_replace((string) ($transformation['search'] ?? ''), (string) ($transformation['replace'] ?? ''), $text),
                'regex_replace' => $this->regexReplace($text, $transformation),
                'extract' => $this->extract($text, $transformation),
                'remove_prefix' => str_starts_with($text, (string) ($transformation['value'] ?? '')) ? substr($text, strlen((string) $transformation['value'])) : $text,
                'lowercase' => mb_strtolower($text),
                'uppercase' => mb_strtoupper($text),
                default => $value,
            };
        }

        return $value;
    }

    /** @param array<string, mixed> $transformation */
    private function regexReplace(string $value, array $transformation): string
    {
        $result = @preg_replace((string) ($transformation['pattern'] ?? ''), (string) ($transformation['replace'] ?? ''), $value);

        return $result ?? $value;
    }

    /** @param array<string, mixed> $transformation */
    private function extract(string $value, array $transformation): string
    {
        if (@preg_match((string) ($transformation['pattern'] ?? ''), $value, $matches) !== 1) {
            return $value;
        }

        return (string) ($matches[(int) ($transformation['group'] ?? 1)] ?? $matches[0]);
    }
}
