<?php

namespace App\Services;

use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use Illuminate\Support\Collection;

class SupplierSuggestionMatcher
{
    /** @var list<string> */
    private array $cities = [];

    /** @param iterable<string|null> $cities */
    public function useCities(iterable $cities): void
    {
        $this->cities = collect([...config('registration.cities', []), ...$cities])->filter()
            ->map($this->clean(...))->unique()->sortByDesc(fn (string $city): int => mb_strlen($city))->values()->all();
    }

    /** @return array{name: string, compact: string, sorted: string, city: ?string, city_label: string, phones: list<string>} */
    public function prepare(ExternalSupplierPartner|Organization $record): array
    {
        $name = $this->clean($record->name);
        $city = $record instanceof ExternalSupplierPartner ? ($record->metadata['city'] ?? null) : $record->city;
        $city = is_string($city) && trim($city) !== '' ? $this->clean($city) : null;
        $inferred = false;
        if ($city === null) {
            foreach ($this->cities as $candidate) {
                if ($this->contains($name, $candidate)) {
                    $city = $candidate;
                    $inferred = true;
                    break;
                }
            }
        }
        foreach (array_unique(array_filter([...$this->cities, $city])) as $knownCity) {
            $name = preg_replace('/(?<![\pL\pN])'.preg_quote($knownCity, '/').'(?![\pL\pN])/u', ' ', $name);
        }
        $name = preg_replace('/(?<![\pL\pN])(?:ооо|оао|зао|ип|чдмм|чдм|ҷдмм|аптека|аптеки|дорухона|дорухонаи|оптовый|оптовая|оптовое|оптом|компания|поставщик|поставщики|склад|фирма|г|город)(?![\pL\pN])/u', ' ', $name);
        $tokens = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $compact = implode('', $tokens);
        sort($tokens, SORT_STRING);
        $phones = $record instanceof ExternalSupplierPartner ? $record->phones : array_filter([$record->phone, ...($record->additional_phones ?? [])]);

        return ['name' => $record->name, 'compact' => $compact, 'sorted' => implode('', $tokens), 'city' => $city,
            'city_label' => $city === null ? 'Город неизвестен' : (($inferred ? 'Город из названия: ' : 'Город: ').$city),
            'phones' => array_values(array_filter(array_unique(array_map(app(ExternalSupplierEligibility::class)->normalizePhone(...), $phones ?? []))))];
    }

    /**
     * @param  array{name: string, compact: string, sorted: string, city: ?string, city_label: string, phones: list<string>}  $left
     * @param  array{name: string, compact: string, sorted: string, city: ?string, city_label: string, phones: list<string>}  $right
     * @return array{similarity: float, score: int|float, evidence: list<string>}|null
     */
    public function compare(array $left, array $right): ?array
    {
        $similarity = max($this->similarity($left['compact'], $right['compact']), $this->similarity($left['sorted'], $right['sorted']));
        $exact = $left['compact'] !== '' && ($left['compact'] === $right['compact'] || $left['sorted'] === $right['sorted']);
        $phone = array_intersect($left['phones'], $right['phones']) !== [];
        if (! $phone && ! $exact && ($similarity < 80 || min(mb_strlen($left['compact']), mb_strlen($right['compact'])) <= 3)) {
            return null;
        }
        if (! $phone && ($left['compact'] === '' || $right['compact'] === '')) {
            return null;
        }
        $evidence = ['Сходство названий: '.round($similarity).'%'];
        if ($exact) {
            $evidence[] = 'Совпадает название';
        }
        if ($phone) {
            $evidence[] = 'Совпадает телефон';
        }
        $score = $phone ? 100 : $similarity;
        if ($left['city'] === null || $right['city'] === null) {
            $evidence[] = 'Город неизвестен';
        } elseif ($left['city'] === $right['city']) {
            $score += 10;
            $evidence[] = 'Совпадает город: '.$left['city'];
        } else {
            $score -= 20;
            $evidence[] = 'Разные города: '.$left['city'].' / '.$right['city'];
        }
        foreach ([$left, $right] as $record) {
            if (str_starts_with($record['city_label'], 'Город из названия')) {
                $evidence[] = $record['city_label'];
            }
        }

        return ['similarity' => $similarity, 'score' => $score, 'evidence' => array_values(array_unique($evidence))];
    }

    /** @param list<array<string, mixed>> $candidates @return list<array<string, mixed>> */
    public function rank(array $candidates): array
    {
        usort($candidates, fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($a['id'] <=> $b['id']));

        return $candidates;
    }

    /** @param list<array<string, mixed>> $candidates */
    public function ambiguous(array $candidates): bool
    {
        return count($candidates) > 1 && $candidates[0]['score'] - $candidates[1]['score'] <= 10;
    }

    /**
     * @param  Collection<int, ExternalSupplierPartner>  $partners
     * @param  Collection<int, Organization>  $suppliers
     * @return array<int, array{internal: list<array<string, mixed>>, external: array<string, list<array<string, mixed>>>, ambiguous: bool, has: bool, city_label: string}>
     */
    public function directory(Collection $partners, Collection $suppliers): array
    {
        $this->useCities(Organization::query()->whereNotNull('city')->distinct()->pluck('city'));
        $preparedPartners = $partners->mapWithKeys(fn (ExternalSupplierPartner $record): array => [$record->id => $this->prepare($record)]);
        $preparedSuppliers = $suppliers->mapWithKeys(fn (Organization $record): array => [$record->id => $this->prepare($record)]);
        $mapped = $partners->whereNotNull('supplier_organization_id')->groupBy('supplier_organization_id');
        $result = [];
        foreach ($partners as $partner) {
            $internal = [];
            $external = ['icorp' => [], 'pricelist_tj' => []];
            if (! $partner->supplier_organization_id) {
                foreach ($suppliers as $supplier) {
                    if ($mapped->get($supplier->id, collect())->contains('source', $partner->source)) {
                        continue;
                    }
                    if ($evidence = $this->compare($preparedPartners[$partner->id], $preparedSuppliers[$supplier->id])) {
                        $internal[] = ['id' => $supplier->id, 'name' => $supplier->name, ...$evidence];
                    }
                }
            }
            foreach ($partners as $candidate) {
                if ($candidate->source === $partner->source || ($partner->supplier_organization_id && $candidate->supplier_organization_id && $partner->supplier_organization_id !== $candidate->supplier_organization_id)) {
                    continue;
                }
                if ($partner->supplier_organization_id && $mapped->get($partner->supplier_organization_id, collect())->contains('source', $candidate->source)) {
                    continue;
                }
                if ($evidence = $this->compare($preparedPartners[$partner->id], $preparedPartners[$candidate->id])) {
                    $external[$candidate->source][] = ['id' => $candidate->id, 'name' => $candidate->name, ...$evidence];
                }
            }
            $internal = $this->rank($internal);
            $external = array_map($this->rank(...), $external);
            $result[$partner->id] = ['internal' => $internal, 'external' => $external,
                'ambiguous' => $this->ambiguous($internal) || collect($external)->contains($this->ambiguous(...)),
                'has' => $internal !== [] || collect($external)->contains(fn (array $items): bool => $items !== []),
                'city_label' => $preparedPartners[$partner->id]['city_label']];
        }

        return $result;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\pL\pN]+/u', ' ', str_replace('ё', 'е', mb_strtolower($value)))));
    }

    private function contains(string $name, string $city): bool
    {
        return preg_match('/(?<![\pL\pN])'.preg_quote($city, '/').'(?![\pL\pN])/u', $name) === 1;
    }

    private function similarity(string $left, string $right): float
    {
        $a = mb_str_split($left);
        $b = mb_str_split($right);
        $length = max(count($a), count($b));
        if ($length === 0) {
            return 0;
        }
        $previous = range(0, count($b));
        foreach ($a as $i => $character) {
            $current = [$i + 1];
            foreach ($b as $j => $other) {
                $current[] = min($current[$j] + 1, $previous[$j + 1] + 1, $previous[$j] + ($character === $other ? 0 : 1));
            }
            $previous = $current;
        }

        return 100 * (1 - $previous[count($b)] / $length);
    }
}
