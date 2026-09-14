<?php

namespace App\Console\Commands;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\ProductCategoryCandidate;
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
            ['Source files', $filePaths->count()],
        ];

        $this->table(['Resource', 'Count'], $counts);
        $this->warn('Run this command only after stopping all price-list import queue workers.');

        if (! $this->option('force')) {
            $this->info('Preview only. Re-run with --force to permanently delete this data.');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            Organization::query()
                ->whereIn('active_price_list_import_id', PriceListImport::query()->select('id'))
                ->update(['active_price_list_import_id' => null]);

            PriceListImportRow::query()->delete();
            Offer::query()->whereNotNull('price_list_import_id')->delete();

            // The model guard protects ordinary lifecycle deletes; this explicitly authorized global purge bypasses it.
            PriceListImport::query()->delete();
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
