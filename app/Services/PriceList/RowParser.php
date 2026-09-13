<?php

namespace App\Services\PriceList;

use App\Enums\PriceListRowAction;
use App\Enums\PriceListRowDisposition;
use App\Models\ProductCategoryRuleSet;
use Carbon\CarbonImmutable;

class RowParser
{
    public function __construct(private readonly ValueNormalizer $normalizer, private readonly ProductCategoryClassifier $classifier) {}

    /** @param array<string, mixed> $row @param array<string, mixed> $profile @return array<string, mixed> */
    public function parse(array $row, int $sourceRow, array $profile): array
    {
        $values = [];
        foreach ($profile['mapping'] as $field => $column) {
            $values[$field] = $this->normalizer->transform($row[strtoupper($column)] ?? null, $profile['transformations'][$field] ?? []);
        }
        $values = array_replace($profile['defaults'] ?? [], $values);
        $raw = $values;
        if (collect($values)->every(fn (mixed $value): bool => $this->normalizer->text($value) === '')) {
            return $this->result($raw, $values, PriceListRowDisposition::Skipped, PriceListRowAction::Skip, [], ['Пустая строка.']);
        }
        if ($skipReason = $this->skipReason($values, $sourceRow, $profile['skip_rules'] ?? [])) {
            return $this->result($raw, $values, PriceListRowDisposition::Skipped, PriceListRowAction::Skip, [], [$skipReason]);
        }

        $values['name'] = $this->normalizer->text($values['name'] ?? null);
        $values['normalized_name'] = $this->normalizer->name($values['name']);
        $originalName = $this->normalizer->text($row[strtoupper((string) ($profile['mapping']['name'] ?? 'A'))] ?? null);
        $category = $originalName === '' ? null : $this->categorize($originalName, $profile);
        $values['normalized_sku'] = $this->normalizer->sku($values['sku'] ?? null);
        $values['price'] = $this->normalizer->decimal($values['price'] ?? null, $profile['decimal_separator'], $profile['thousands_separator']);
        $values['quantity'] = $this->normalizer->decimal($values['quantity'] ?? null, $profile['decimal_separator'], $profile['thousands_separator']);
        $values['total'] = $this->normalizer->decimal($values['total'] ?? null, $profile['decimal_separator'], $profile['thousands_separator']);
        $errors = [];
        $warnings = [];
        if ($values['normalized_name'] === '') {
            $errors[] = 'Название товара не указано.';
        }
        if ($values['price'] === null || (float) $values['price'] <= 0) {
            $errors[] = 'Цена должна быть положительным числом.';
        }
        if ($profile['matching_strategy'] === 'sku' && $values['normalized_sku'] === null) {
            $errors[] = 'Для сопоставления по SKU необходим артикул.';
        }
        if ($values['quantity'] !== null && (float) $values['quantity'] < 0) {
            $errors[] = 'Количество не может быть отрицательным.';
        }
        if ($values['total'] !== null && (float) $values['total'] < 0) {
            $errors[] = 'Сумма не может быть отрицательной.';
        }
        if ($values['quantity'] !== null && (float) $values['quantity'] === 0.0) {
            return $this->result($raw, $values, PriceListRowDisposition::Skipped, PriceListRowAction::Skip, [], ['Товар отсутствует (количество 0).']);
        }
        $expiration = $values['expiration'] ?? null;
        if ($expiration !== null && trim((string) $expiration) !== '') {
            $parsedExpiration = ($profile['expiration_mode'] ?? 'date') === 'shelf_life'
                ? $this->shelfLifeDate($expiration, $profile)
                : $this->normalizer->date($expiration, $profile['date_formats']);
            if ($parsedExpiration === null) {
                $errors[] = 'Не удалось распознать срок годности.';
            } else {
                $values['expiration'] = $parsedExpiration->toDateString();
                $today = CarbonImmutable::now(config('price-list-imports.timezone'))->startOfDay();
                if ($parsedExpiration->lt($today)) {
                    return $this->result($raw, $values, PriceListRowDisposition::Skipped, PriceListRowAction::Skip, [], ['Срок годности истёк.']);
                }
                if ($parsedExpiration->lte($today->addDays((int) config('price-list-imports.near_expiry_days')))) {
                    $warnings[] = 'Срок годности скоро истекает.';
                }
            }
        } else {
            $values['expiration'] = null;
        }

        $disposition = $errors !== [] ? PriceListRowDisposition::Error : ($warnings !== [] ? PriceListRowDisposition::Warning : PriceListRowDisposition::Valid);
        $result = $this->result($raw, $values, $disposition, PriceListRowAction::Create, $errors, $warnings);
        if ($category !== null && $category !== []) {
            $result = array_replace($result, $category);
            if (in_array($category['categorization_status'], ['unmatched', 'ambiguous'], true)) {
                $result['warnings'][] = $category['categorization_status'] === 'ambiguous' ? 'Категория товара неоднозначна и требует проверки.' : 'Категория товара не распознана.';
                if ($result['disposition'] === PriceListRowDisposition::Valid) {
                    $result['disposition'] = PriceListRowDisposition::Warning;
                }
            }
        }
        $result['offer_fingerprint'] = hash('sha256', json_encode([$values['normalized_name'], $values['price'], $values['quantity'], $values['batch'] ?? null, $values['expiration']], JSON_UNESCAPED_UNICODE));

        return $result;
    }

    /** @param array<string, mixed> $profile */
    private function shelfLifeDate(mixed $value, array $profile): ?CarbonImmutable
    {
        if (! is_numeric($value) || (float) $value < 0 || empty($profile['_inventory_at'])) {
            return null;
        }
        $base = CarbonImmutable::parse($profile['_inventory_at'], config('price-list-imports.timezone'));

        return ($profile['shelf_life_unit'] ?? 'days') === 'months' ? $base->addMonths((int) $value) : $base->addDays((int) $value);
    }

    /** @param array<string, mixed> $values @param list<array<string, mixed>> $rules */
    private function skipReason(array $values, int $row, array $rules): ?string
    {
        foreach ($rules as $rule) {
            $type = $rule['type'] ?? null;
            $skip = match ($type) {
                'row_range' => $row >= (int) ($rule['from'] ?? 0) && $row <= (int) ($rule['to'] ?? PHP_INT_MAX),
                'content' => $this->normalizer->text($values[$rule['field'] ?? 'name'] ?? '') === (string) ($rule['value'] ?? ''),
                'regex' => @preg_match((string) ($rule['pattern'] ?? ''), $this->normalizer->text($values[$rule['field'] ?? 'name'] ?? '')) === 1,
                default => false,
            };
            if ($skip) {
                return (string) ($rule['reason'] ?? 'Строка пропущена по правилу профиля.');
            }
        }

        return null;
    }

    /** @param array<string, mixed> $raw @param array<string, mixed> $parsed @param list<string> $errors @param list<string> $warnings @return array<string, mixed> */
    private function result(array $raw, array $parsed, PriceListRowDisposition $disposition, PriceListRowAction $action, array $errors, array $warnings): array
    {
        return ['raw_values' => $raw, 'parsed_values' => $parsed, 'disposition' => $disposition, 'planned_action' => $action, 'errors' => $errors, 'warnings' => $warnings, 'offer_fingerprint' => null];
    }

    /** @param array<string, mixed> $profile @return array<string, mixed> */
    private function categorize(string $originalName, array $profile): array
    {
        $ruleSetId = $profile['_category_rule_set_id'] ?? null;
        if ($ruleSetId === null) {
            return [];
        }
        $ruleSet = ProductCategoryRuleSet::find($ruleSetId);
        if ($ruleSet === null) {
            return [];
        }
        $result = $this->classifier->classify($originalName, $ruleSet);

        return ['source_filename' => $profile['_source_filename'] ?? null, 'original_product_name' => $originalName, 'normalized_product_name' => $result['normalized'], 'assigned_category' => $result['category'], 'matched_keyword' => $result['keyword'], 'matched_source_text' => $result['sourceText'], 'categorization_confidence' => $result['confidence'], 'categorization_status' => $result['status'], 'categorization_evidence' => $result['evidence']];
    }
}
