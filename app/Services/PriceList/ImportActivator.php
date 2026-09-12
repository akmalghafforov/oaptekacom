<?php

namespace App\Services\PriceList;

use App\Enums\PriceListImportStatus;
use App\Enums\PriceListRowDisposition;
use App\Models\Medicine;
use App\Models\Organization;
use App\Models\PriceListImport;
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
            if ($supplier->activePriceListImport?->inventory_at && $lockedImport->inventory_at && $lockedImport->inventory_at->lt($supplier->activePriceListImport->inventory_at)) {
                throw ValidationException::withMessages(['import' => 'Дата остатков старше текущего прайс-листа.']);
            }
            $rows = $lockedImport->rows()->whereIn('disposition', [PriceListRowDisposition::Valid, PriceListRowDisposition::Warning])->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['import' => 'Нет корректных строк для активации.']);
            }
            $lockedImport->update(['status' => PriceListImportStatus::Committing]);
            foreach ($rows as $row) {
                $values = $row->parsed_values;
                $medicine = $row->medicine ?? Medicine::create([
                    'name' => $values['name'], 'normalized_name' => $values['normalized_name'], 'search_text' => $values['normalized_name'],
                    'manufacturer' => $values['manufacturer'] ?? null, 'country_of_origin' => $values['country'] ?? null, 'unit_of_measure' => $values['unit'] ?? null,
                ]);
                $this->fillCanonicalNulls($medicine, $values, $row);
                $matchKey = $values['normalized_sku'] ?? $values['normalized_name'];
                $supplierProduct = $row->supplierProduct ?? SupplierProduct::firstOrCreate(
                    ['supplier_organization_id' => $supplier->id, 'match_key' => $matchKey],
                    ['medicine_id' => $medicine->id, 'supplier_sku' => $values['sku'] ?? null, 'normalized_sku' => $values['normalized_sku'], 'original_name' => $values['name'], 'normalized_name' => $values['normalized_name']],
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
                PriceListImport::query()->whereKey($supplier->active_price_list_import_id)->update(['status' => PriceListImportStatus::Superseded, 'superseded_at' => now()]);
                $supplier->offers()->where('price_list_import_id', $supplier->active_price_list_import_id)->update(['is_active' => false]);
            }
            $lockedImport->offers()->update(['is_active' => true]);
            $lockedImport->update(['status' => PriceListImportStatus::Completed, 'activated_by' => $actor?->id, 'activated_at' => now()]);
            $supplier->update(['active_price_list_import_id' => $lockedImport->id]);
            $lockedImport->rows()->whereIn('disposition', [PriceListRowDisposition::Valid])->delete();
            app(AuditLogger::class)->log('price_list_import.activated', $lockedImport, [], ['supplier_organization_id' => $supplier->id, 'valid_rows' => $rows->count()]);

            return $lockedImport->fresh();
        });
    }

    /** @param array<string, mixed> $values */
    private function fillCanonicalNulls(Medicine $medicine, array $values, mixed $row): void
    {
        $changes = [];
        foreach (['manufacturer' => 'manufacturer', 'country' => 'country_of_origin', 'unit' => 'unit_of_measure'] as $source => $canonical) {
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
}
