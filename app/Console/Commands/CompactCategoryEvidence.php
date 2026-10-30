<?php

namespace App\Console\Commands;

use App\Services\PriceList\CategoryEvidence;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

#[Signature('price-list-imports:compact-category-evidence {--dry-run} {--supplier=} {--chunk=100}')]
#[Description('Remove recursively embedded category evidence from import rows and medicine pivots')]
class CompactCategoryEvidence extends Command
{
    public function handle(CategoryEvidence $evidence): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $supplier = $this->option('supplier');
        if ($chunk === false || ($supplier !== null && filter_var($supplier, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)) {
            $this->error('The chunk size and supplier ID must be positive integers.');

            return self::INVALID;
        }

        $rows = DB::table('price_list_import_rows')->select('id');
        $pivots = DB::table('medicine_product_category')->select('id');
        if ($supplier !== null) {
            $rows->whereIn('price_list_import_id', DB::table('price_list_imports')->select('id')->where('supplier_organization_id', $supplier));
            $pivots->whereIn('medicine_id', DB::table('medicines')->select('id')->where('supplier_organization_id', $supplier));
        }

        $this->info($this->option('dry-run') ? 'Dry run: no records will be updated.' : 'Compacting category evidence.');
        $this->compact($rows, ['category_candidates', 'categorization_evidence'], $chunk, $evidence);
        $this->compact($pivots, ['evidence'], $chunk, $evidence);

        return self::SUCCESS;
    }

    /** @param list<string> $columns */
    private function compact(Builder $query, array $columns, int $chunkSize, CategoryEvidence $evidence): void
    {
        $scanned = $changed = $before = $after = 0;
        foreach ($query->lazyById($chunkSize)->chunk($chunkSize) as $batch) {
            DB::transaction(function () use ($query, $columns, $evidence, $batch, &$scanned, &$changed, &$before, &$after): void {
                $records = DB::table($query->from)
                    ->whereIn('id', $batch->pluck('id')->all())
                    ->orderBy('id');
                if (! $this->option('dry-run')) {
                    $records->lockForUpdate();
                }

                foreach ($records->get(['id', ...$columns]) as $record) {
                    $scanned++;
                    $updates = [];
                    foreach ($columns as $column) {
                        $raw = $record->{$column};
                        $before += strlen($raw ?? '');
                        if ($raw !== null) {
                            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
                            if (is_array($decoded) || $decoded instanceof stdClass) {
                                $clean = $evidence->sanitize($decoded);
                                if ($clean != $decoded) {
                                    $updates[$column] = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                                }
                            }
                        }
                        $after += strlen($updates[$column] ?? $raw ?? '');
                    }
                    if ($updates !== []) {
                        $changed++;
                        if (! $this->option('dry-run')) {
                            DB::table($query->from)->where('id', $record->id)->update($updates);
                        }
                    }
                }
            });
        }
        $this->line("{$query->from}: scanned={$scanned} changed={$changed} bytes_before={$before} bytes_after={$after} bytes_saved=".($before - $after));
    }
}
