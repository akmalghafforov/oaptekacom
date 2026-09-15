<?php

namespace App\Console\Commands;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\ProductCategoryCandidate;
use App\Models\SupplierProduct;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('price-list-imports:purge {--force : Permanently delete all imported offer data and source files}')]
#[Description('Preview or permanently purge all imported offers and price-list imports')]
class PurgePriceListImports extends Command
{
    public function handle(): int
    {
        $filePaths = PriceListImport::query()
            ->whereNotNull('file_path')
            ->pluck('file_path')
            ->filter(fn (mixed $filePath): bool => is_string($filePath) && $filePath !== '')
            ->unique()
            ->values();

        $counts = [
            ['Imports', PriceListImport::query()->count()],
            ['Imported offers', Offer::query()->whereNotNull('price_list_import_id')->count()],
            ['Import rows', PriceListImportRow::query()->count()],
            ['Category candidates', ProductCategoryCandidate::query()->count()],
            ['Supplier products', SupplierProduct::query()->count()],
            ['Source files', $filePaths->count()],
        ];

        $this->table(['Resource', 'Count'], $counts);
        $this->warn('Run this command only after stopping all price-list import queue workers.');

        if (! $this->option('force')) {
            $this->info('Preview only. Re-run with --force to permanently delete this data.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            $medicineIds = DB::table('supplier_products')->pluck('medicine_id')->merge(DB::table('medicines')->whereNotNull('supplier_organization_id')->pluck('id'))->unique()->values();
            Organization::query()
                ->whereIn('active_price_list_import_id', PriceListImport::query()->select('id'))
                ->update(['active_price_list_import_id' => null]);

            DB::table('order_items')->whereIn('medicine_id', $medicineIds)->orWhereIn('offer_id', Offer::query()->whereNotNull('price_list_import_id')->select('id'))->update(['offer_id' => null, 'medicine_id' => null]);
            DB::table('cart_items')->whereIn('offer_id', Offer::query()->whereNotNull('price_list_import_id')->select('id'))->delete();
            DB::table('price_list_imports')->update(['duplicate_of_import_id' => null]);
            PriceListImportRow::query()->delete();
            Offer::query()->whereNotNull('price_list_import_id')->delete();
            DB::table('supplier_product_aliases')->delete();
            DB::table('supplier_products')->delete();
            PriceListImport::query()->delete();
            DB::table('medicines')->whereIn('id', $medicineIds)->delete();
        });

        $failedFilePaths = [];
        $disk = Storage::disk(config('price-list-imports.disk'));
        foreach ($filePaths as $filePath) {
            try {
                if (! $disk->delete($filePath)) {
                    $failedFilePaths[] = $filePath;
                }
            } catch (Throwable $exception) {
                $failedFilePaths[] = $filePath;
                $this->error('Could not delete source file '.$filePath.': '.$exception->getMessage());
            }
        }

        if ($failedFilePaths !== []) {
            $this->error('Imported database data was deleted, but '.count($failedFilePaths).' source file(s) could not be removed.');

            return self::FAILURE;
        }

        $this->info('All imported offer data and source files have been purged.');

        return self::SUCCESS;
    }
}
