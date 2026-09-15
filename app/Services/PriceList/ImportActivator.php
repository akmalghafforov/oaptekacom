<?php

namespace App\Services\PriceList;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowDisposition;
use App\Models\AuditEvent;
use App\Models\CartItem;
use App\Models\Medicine;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\ProductCategory;
use App\Models\SupplierProduct;
use App\Models\SupplierProductAlias;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImportActivator
{
    public function activate(PriceListImport $import, ?User $actor = null): PriceListImport
    {
        $ids = $this->eligibleRows($import)->orderBy('id')->pluck('id');
        foreach ($ids->chunk((int) config('price-list-imports.activation_chunk_size', 500)) as $chunk) {
            $this->materialize($import->fresh(), (int) $chunk->first(), (int) $chunk->last(), $actor?->id);
        }

        $result = $this->commit($import->fresh(), $actor);
        $this->cleanup($result['import']->id, $result['superseded_import_id']);

        return $result['import'];
    }

    public function reconcileStagedOffers(PriceListImport $import): void
    {
        $import->offers()->whereNotIn('source_row', $this->eligibleRows($import)->select('source_row'))->delete();
    }

    public function materialize(PriceListImport $import, int $firstRowId, int $lastRowId, ?int $actorId = null): void
    {
        $changed = PriceListImport::query()->whereKey($import->id)->where('status', PriceListImportStatus::Preview)
            ->update(['status' => PriceListImportStatus::Committing]);
        $import = $import->fresh();
        if ($changed === 0 && $import->status !== PriceListImportStatus::Committing) {
            return;
        }

        $rows = $this->eligibleRows($import)->whereBetween('id', [$firstRowId, $lastRowId])->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return;
        }

        $supplierId = $import->supplier_organization_id;
        $representatives = $rows->groupBy(fn (PriceListImportRow $row): string => (string) $row->parsed_values['normalized_name'])
            ->map(fn (Collection $group): PriceListImportRow => $group->first());
        $now = now();

        Medicine::query()->insertOrIgnore($representatives->filter(fn (PriceListImportRow $row): bool => $row->medicine_id === null)->map(function (PriceListImportRow $row) use ($import, $supplierId, $now): array {
            $values = $row->parsed_values;

            return [
                'supplier_organization_id' => $supplierId, 'normalized_name' => $values['normalized_name'],
                'name' => $values['name'], 'search_text' => $values['normalized_name'],
                'manufacturer' => $values['manufacturer'] ?? null, 'country_of_origin' => $values['country'] ?? null,
                'unit_of_measure' => $values['unit'] ?? null, 'inn' => $values['inn'] ?? null,
                'form' => $values['form'] ?? null, 'dosage' => $values['dosage'] ?? null,
                'category' => $row->assigned_category ?? 'Не распознано',
                'category_status' => $row->categorization_status ?? 'uncategorized',
                'category_rule_set_id' => $import->product_category_rule_set_id,
                'category_rule_set_checksum' => $import->product_category_rule_set_checksum,
                'category_confidence' => $row->categorization_confidence,
                'category_evidence' => $this->json($row->categorization_evidence), 'category_assigned_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ];
        })->values()->all());

        $medicines = Medicine::query()->whereIn('id', $representatives->pluck('medicine_id')->filter())->get()
            ->mapWithKeys(function (Medicine $medicine) use ($representatives): array {
                $name = $representatives->firstWhere('medicine_id', $medicine->id)->parsed_values['normalized_name'];

                return [$name => $medicine];
            });
        Medicine::query()->where('supplier_organization_id', $supplierId)
            ->whereIn('normalized_name', $representatives->keys()->diff($medicines->keys()))
            ->get()->each(fn (Medicine $medicine) => $medicines->put($medicine->normalized_name, $medicine));
        $this->updateCanonicalValuesAndWarnings($rows, $medicines);
        $this->replaceAutomaticCategories($representatives, $medicines, $import, $actorId);

        SupplierProduct::query()->insertOrIgnore($representatives->filter(fn (PriceListImportRow $row): bool => $row->supplier_product_id === null)->map(function (PriceListImportRow $row) use ($supplierId, $medicines, $now): array {
            $values = $row->parsed_values;

            return [
                'supplier_organization_id' => $supplierId, 'medicine_id' => $medicines[$values['normalized_name']]->id,
                'supplier_sku' => $values['sku'] ?? null, 'normalized_sku' => $values['normalized_sku'] ?? null,
                'original_name' => $values['name'], 'normalized_name' => $values['normalized_name'],
                'match_key' => $values['normalized_name'], 'created_at' => $now, 'updated_at' => $now,
            ];
        })->values()->all());

        $products = SupplierProduct::query()->whereIn('id', $representatives->pluck('supplier_product_id')->filter())->get()
            ->mapWithKeys(function (SupplierProduct $product) use ($representatives): array {
                $name = $representatives->firstWhere('supplier_product_id', $product->id)->parsed_values['normalized_name'];

                return [$name => $product];
            });
        SupplierProduct::query()->where('supplier_organization_id', $supplierId)
            ->whereIn('normalized_name', $representatives->keys()->diff($products->keys()))
            ->get()->each(fn (SupplierProduct $product) => $products->put($product->normalized_name, $product));

        SupplierProductAlias::query()->insertOrIgnore($representatives->map(function (PriceListImportRow $row) use ($supplierId, $products, $actorId, $now): array {
            $name = $row->parsed_values['normalized_name'];

            return ['supplier_organization_id' => $supplierId, 'supplier_product_id' => $products[$name]->id,
                'normalized_name' => $name, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now];
        })->values()->all());

        Offer::query()->upsert($rows->map(function (PriceListImportRow $row) use ($import, $supplierId, $medicines, $products, $now): array {
            $values = $row->parsed_values;
            $name = $values['normalized_name'];

            return [
                'price_list_import_id' => $import->id, 'source_row' => $row->source_row,
                'organization_id' => $supplierId, 'medicine_id' => $medicines[$name]->id,
                'supplier_product_id' => $products[$name]->id, 'source_name' => $values['name'],
                'price' => $values['price'], 'quantity' => $values['quantity'], 'stock' => $values['quantity'] ?? 0,
                'batch' => $values['batch'] ?? null, 'expires_at' => $values['expiration'],
                'total_value' => $values['total'], 'imported_unit' => $values['unit'] ?? null,
                'is_active' => false, 'created_at' => $now, 'updated_at' => $now,
            ];
        })->all(), ['price_list_import_id', 'source_row'], [
            'organization_id', 'medicine_id', 'supplier_product_id', 'source_name', 'price', 'quantity', 'stock',
            'batch', 'expires_at', 'total_value', 'imported_unit', 'is_active', 'updated_at',
        ]);

        $offers = $import->offers()->whereIn('source_row', $rows->pluck('source_row'))->get()->keyBy('source_row');
        PriceListImportRow::query()->upsert($rows->map(function (PriceListImportRow $row) use ($medicines, $products, $offers, $now): array {
            $name = $row->parsed_values['normalized_name'];

            return array_replace($row->getAttributes(), [
                'medicine_id' => $medicines[$name]->id, 'supplier_product_id' => $products[$name]->id,
                'offer_id' => $offers[$row->source_row]->id, 'updated_at' => $now,
            ]);
        })->all(), ['id'], ['medicine_id', 'supplier_product_id', 'offer_id', 'updated_at']);

        if ($this->eligibleRows($import)->whereBetween('id', [$firstRowId, $lastRowId])
            ->where(fn ($query) => $query->whereNull('medicine_id')->orWhereNull('supplier_product_id')->orWhereNull('offer_id'))->exists()) {
            throw new RuntimeException('Не все строки диапазона материализованы.');
        }
    }

    /** @return array{import: PriceListImport, superseded_import_id: int|null} */
    public function commit(PriceListImport $import, ?User $actor = null): array
    {
        return DB::transaction(function () use ($import, $actor): array {
            $supplier = Organization::query()->lockForUpdate()->findOrFail($import->supplier_organization_id);
            $ids = array_values(array_unique(array_filter([$import->id, $supplier->active_price_list_import_id])));
            $imports = PriceListImport::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $candidate = $imports[$import->id];

            if ($supplier->active_price_list_import_id === $candidate->id && $candidate->status === PriceListImportStatus::Completed) {
                return ['import' => $candidate, 'superseded_import_id' => null];
            }
            if ($candidate->status !== PriceListImportStatus::Committing) {
                throw ValidationException::withMessages(['import' => 'Импорт ещё не готов к активации.']);
            }

            $current = $supplier->active_price_list_import_id === null ? null : $imports[$supplier->active_price_list_import_id];
            if ($current !== null && $this->isOlderThan($candidate, $current)) {
                throw ValidationException::withMessages(['import' => 'Дата остатков старше текущего прайс-листа.']);
            }

            $eligible = $this->eligibleRows($candidate)->count();
            $linked = $this->eligibleRows($candidate)->whereNotNull('medicine_id')->whereNotNull('supplier_product_id')->whereNotNull('offer_id')->count();
            $matchingOffers = $candidate->offers()->whereHas('importRow', fn ($query) => $query->whereIn('disposition', [PriceListRowDisposition::Valid, PriceListRowDisposition::Warning]))->count();
            if ($eligible === 0) {
                throw ValidationException::withMessages(['import' => 'Нет корректных строк для активации.']);
            }
            if ($eligible !== $linked || $eligible !== $matchingOffers) {
                throw new RuntimeException('Не все строки импорта материализованы.');
            }
            if ($candidate->offers()->where('organization_id', '!=', $supplier->id)->exists()) {
                throw new RuntimeException('Материализованные предложения не принадлежат поставщику.');
            }

            $supersededId = $current?->id;
            $current?->update(['status' => PriceListImportStatus::Superseded, 'superseded_at' => now()]);
            $candidate->update(['status' => PriceListImportStatus::Completed, 'activated_by' => $actor?->id,
                'activated_at' => now(), 'failed_at' => null, 'failure_stage' => null, 'failure_message' => null]);
            $supplier->update(['active_price_list_import_id' => $candidate->id]);

            if (! AuditEvent::query()->where('event', 'price_list_import.activated')->where('subject_type', PriceListImport::class)->where('subject_id', $candidate->id)->exists()) {
                app(AuditLogger::class)->log('price_list_import.activated', $candidate, [], ['supplier_organization_id' => $supplier->id, 'valid_rows' => $eligible]);
            }

            return ['import' => $candidate->fresh(), 'superseded_import_id' => $supersededId];
        }, 3);
    }

    public function cleanup(int $activeImportId, ?int $supersededImportId): void
    {
        Offer::query()->where('price_list_import_id', $activeImportId)->where('is_active', false)->update(['is_active' => true]);
        if ($supersededImportId !== null) {
            CartItem::query()->whereHas('offer', fn ($query) => $query->where('price_list_import_id', $supersededImportId))->delete();
            Offer::query()->where('price_list_import_id', $supersededImportId)->where('is_active', true)->update(['is_active' => false]);
        }
    }

    /** @param Collection<int, PriceListImportRow> $rows @param Collection<string, Medicine> $medicines */
    private function updateCanonicalValuesAndWarnings(Collection $rows, Collection $medicines): void
    {
        foreach ($rows as $row) {
            $values = $row->parsed_values;
            $medicine = $medicines[$values['normalized_name']];
            $changes = [];
            $warnings = $row->warnings ?? [];
            foreach (['manufacturer' => 'manufacturer', 'country' => 'country_of_origin', 'unit' => 'unit_of_measure', 'inn' => 'inn', 'form' => 'form', 'dosage' => 'dosage'] as $source => $canonical) {
                if ($medicine->{$canonical} === null && ! empty($values[$source])) {
                    $changes[$canonical] = $values[$source];
                    $medicine->{$canonical} = $values[$source];
                } elseif ($medicine->{$canonical} && ! empty($values[$source]) && app(ValueNormalizer::class)->name($medicine->{$canonical}) !== app(ValueNormalizer::class)->name($values[$source])) {
                    $warning = 'Значение '.$canonical.' отличается от канонического и не было заменено.';
                    if (! in_array($warning, $warnings, true)) {
                        $warnings[] = $warning;
                    }
                }
            }
            if ($changes !== []) {
                $medicine->update($changes);
            }
            if ($warnings !== ($row->warnings ?? [])) {
                $row->update(['warnings' => $warnings, 'disposition' => PriceListRowDisposition::Warning]);
            }
        }
    }

    /** @param Collection<string, PriceListImportRow> $rows @param Collection<string, Medicine> $medicines */
    private function replaceAutomaticCategories(Collection $rows, Collection $medicines, PriceListImport $import, ?int $actorId): void
    {
        $rows = $rows->filter(fn (PriceListImportRow $row): bool => $medicines[$row->parsed_values['normalized_name']]->categories_locked_at === null);
        $medicineIds = $rows->map(fn (PriceListImportRow $row): int => $medicines[$row->parsed_values['normalized_name']]->id)->values();
        if ($medicineIds->isEmpty()) {
            return;
        }

        DB::table('medicine_product_category')->whereIn('medicine_id', $medicineIds)->delete();
        $categoryIds = ProductCategory::query()->whereIn('code', $rows->flatMap(fn (PriceListImportRow $row): array => $row->assigned_categories ?? [])->unique())->pluck('id', 'code');
        $now = now();
        $pivots = [];
        foreach ($rows as $row) {
            $candidates = collect($row->category_candidates ?? [])->keyBy('code');
            foreach ($row->assigned_categories ?? [] as $code) {
                if (! isset($categoryIds[$code])) {
                    continue;
                }
                $candidate = $candidates->get($code, []);
                $pivots[] = ['medicine_id' => $medicines[$row->parsed_values['normalized_name']]->id,
                    'product_category_id' => $categoryIds[$code], 'source' => 'automatic',
                    'confidence' => $candidate['confidence'] ?? 0, 'rule_set_id' => $import->product_category_rule_set_id,
                    'rule_set_checksum' => $import->product_category_rule_set_checksum, 'evidence' => $this->json($candidate),
                    'assigned_by' => $actorId, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($pivots !== []) {
            DB::table('medicine_product_category')->upsert($pivots, ['medicine_id', 'product_category_id'], ['source', 'confidence', 'rule_set_id', 'rule_set_checksum', 'evidence', 'assigned_by', 'updated_at']);
        }
    }

    private function eligibleRows(PriceListImport $import): mixed
    {
        return $import->rows()->whereIn('disposition', [PriceListRowDisposition::Valid, PriceListRowDisposition::Warning]);
    }

    private function json(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function isOlderThan(PriceListImport $candidate, PriceListImport $current): bool
    {
        if ($candidate->inventory_at === null || $current->inventory_at === null) {
            return $candidate->id < $current->id;
        }

        return $candidate->inventory_at->lt($current->inventory_at)
            || ($candidate->inventory_at->equalTo($current->inventory_at) && $candidate->id < $current->id);
    }
}
