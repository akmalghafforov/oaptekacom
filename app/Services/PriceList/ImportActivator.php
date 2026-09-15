<?php

namespace App\Services\PriceList;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowDisposition;
use App\Models\CartItem;
use App\Models\Medicine;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\ProductCategory;
use App\Models\SupplierProduct;
use App\Models\SupplierProductAlias;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportActivator
{
    public function activate(PriceListImport $import, ?User $actor = null): PriceListImport
    {
        return DB::transaction(function () use ($import, $actor): PriceListImport {
            $lockedImport = PriceListImport::query()->lockForUpdate()->findOrFail($import->id);
            $supplier = Organization::query()->lockForUpdate()->findOrFail($lockedImport->supplier_organization_id);
            if ($supplier->active_price_list_import_id === $lockedImport->id && $lockedImport->status === PriceListImportStatus::Completed) {
                return $lockedImport;
            }
            if ($lockedImport->status !== PriceListImportStatus::Preview) {
                throw ValidationException::withMessages(['import' => 'Импорт ещё не готов к активации.']);
            }
            $currentImport = $supplier->activePriceListImport;
            if ($currentImport !== null && $this->isOlderThan($lockedImport, $currentImport)) {
                throw ValidationException::withMessages(['import' => 'Дата остатков старше текущего прайс-листа.']);
            }
            $rows = $lockedImport->rows()->whereIn('disposition', [PriceListRowDisposition::Valid, PriceListRowDisposition::Warning])->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['import' => 'Нет корректных строк для активации.']);
            }
            $lockedImport->update(['status' => PriceListImportStatus::Committing]);
            foreach ($rows as $row) {
                $values = $row->parsed_values;
                $medicine = $row->medicine ?? Medicine::firstOrCreate(
                    ['supplier_organization_id' => $supplier->id, 'normalized_name' => $values['normalized_name']],
                    [
                        'name' => $values['name'], 'search_text' => $values['normalized_name'],
                        'manufacturer' => $values['manufacturer'] ?? null, 'country_of_origin' => $values['country'] ?? null,
                        'unit_of_measure' => $values['unit'] ?? null, 'inn' => $values['inn'] ?? null,
                        'form' => $values['form'] ?? null, 'dosage' => $values['dosage'] ?? null,
                        'category' => $row->assigned_category ?? 'Не распознано',
                        'category_status' => $row->categorization_status ?? 'unmatched',
                        'category_rule_set_id' => $lockedImport->product_category_rule_set_id,
                        'category_rule_set_checksum' => $lockedImport->product_category_rule_set_checksum,
                        'category_confidence' => $row->categorization_confidence,
                        'category_evidence' => $row->categorization_evidence,
                        'category_assigned_at' => now(),
                    ],
                );
                if ($medicine->categories_locked_at === null) {
                    $this->syncCategories($medicine, $row, $lockedImport, $actor);
                }
                $this->fillCanonicalNulls($medicine, $values, $row);
                $supplierProduct = $row->supplierProduct ?? SupplierProduct::firstOrCreate(
                    ['supplier_organization_id' => $supplier->id, 'normalized_name' => $values['normalized_name']],
                    ['medicine_id' => $medicine->id, 'supplier_sku' => $values['sku'] ?? null, 'normalized_sku' => $values['normalized_sku'], 'original_name' => $values['name'], 'match_key' => $values['normalized_name']],
                );
                SupplierProductAlias::firstOrCreate(['supplier_organization_id' => $supplier->id, 'normalized_name' => $values['normalized_name']], ['supplier_product_id' => $supplierProduct->id, 'created_by' => $actor?->id]);
                $offer = $lockedImport->offers()->updateOrCreate(['source_row' => $row->source_row], [
                    'organization_id' => $supplier->id, 'medicine_id' => $medicine->id, 'supplier_product_id' => $supplierProduct->id,
                    'source_name' => $values['name'], 'price' => $values['price'], 'quantity' => $values['quantity'], 'stock' => $values['quantity'] ?? 0,
                    'batch' => $values['batch'] ?? null, 'expires_at' => $values['expiration'], 'total_value' => $values['total'], 'imported_unit' => $values['unit'] ?? null, 'is_active' => false,
                ]);
                $row->update(['medicine_id' => $medicine->id, 'supplier_product_id' => $supplierProduct->id, 'offer_id' => $offer->id]);
            }
            if ($lockedImport->offers()->count() !== $rows->count()) {
                throw new \RuntimeException('Не все строки импорта материализованы.');
            }
            if ($supplier->active_price_list_import_id) {
                CartItem::query()->whereHas('offer', fn ($query) => $query->where('price_list_import_id', $supplier->active_price_list_import_id))->delete();
                PriceListImport::query()->whereKey($supplier->active_price_list_import_id)->update(['status' => PriceListImportStatus::Superseded, 'superseded_at' => now()]);
                $supplier->offers()->where('price_list_import_id', $supplier->active_price_list_import_id)->update(['is_active' => false]);
            }
            $lockedImport->offers()->update(['is_active' => true]);
            $lockedImport->update(['status' => PriceListImportStatus::Completed, 'activated_by' => $actor?->id, 'activated_at' => now()]);
            $supplier->update(['active_price_list_import_id' => $lockedImport->id]);
            app(AuditLogger::class)->log('price_list_import.activated', $lockedImport, [], ['supplier_organization_id' => $supplier->id, 'valid_rows' => $rows->count()]);

            return $lockedImport->fresh();
        });
    }

    private function syncCategories(Medicine $medicine, mixed $row, PriceListImport $import, ?User $actor): void
    {
        $categories = ProductCategory::query()->whereIn('code', $row->assigned_categories ?? [])->get();
        $candidateByCode = collect($row->category_candidates ?? [])->keyBy('code');
        $sync = $categories->mapWithKeys(function ($category) use ($candidateByCode, $import, $actor): array {
            $candidate = $candidateByCode->get($category->code, []);

            return [$category->id => ['source' => 'automatic', 'confidence' => $candidate['confidence'] ?? 0, 'rule_set_id' => $import->product_category_rule_set_id, 'rule_set_checksum' => $import->product_category_rule_set_checksum, 'evidence' => json_encode($candidate, JSON_UNESCAPED_UNICODE), 'assigned_by' => $actor?->id]];
        })->all();
        $medicine->categories()->sync($sync);
    }

    /** @param array<string, mixed> $values */
    private function fillCanonicalNulls(Medicine $medicine, array $values, mixed $row): void
    {
        $changes = [];
        foreach (['manufacturer' => 'manufacturer', 'country' => 'country_of_origin', 'unit' => 'unit_of_measure', 'inn' => 'inn', 'form' => 'form', 'dosage' => 'dosage'] as $source => $canonical) {
            if ($medicine->{$canonical} === null && ! empty($values[$source])) {
                $changes[$canonical] = $values[$source];
            } elseif ($medicine->{$canonical} && ! empty($values[$source]) && app(ValueNormalizer::class)->name($medicine->{$canonical}) !== app(ValueNormalizer::class)->name($values[$source])) {
                $warnings = $row->warnings ?? [];
                $warnings[] = 'Значение '.$canonical.' отличается от канонического и не было заменено.';
                $row->update(['warnings' => $warnings, 'disposition' => PriceListRowDisposition::Warning]);
            }
        }
        if ($changes !== []) {
            $medicine->update($changes);
        }
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
