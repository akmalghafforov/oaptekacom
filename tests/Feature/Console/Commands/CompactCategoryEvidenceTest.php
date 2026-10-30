<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Medicine;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\ProductCategory;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CompactCategoryEvidenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dry_run_reports_counts_and_bytes_without_changing_evidence(): void
    {
        [$row, $pivotId, $clean] = $this->corruptedEvidence(Organization::factory()->wholesaler()->create());
        $rowBefore = $row->fresh()->getRawOriginal();
        $pivotBefore = (array) DB::table('medicine_product_category')->find($pivotId);
        $rowBytesBefore = strlen($rowBefore['category_candidates']) + strlen($rowBefore['categorization_evidence']);
        $rowBytesAfter = strlen(json_encode([$clean], JSON_UNESCAPED_UNICODE)) + strlen(json_encode(['candidates' => [$clean], 'issues' => []], JSON_UNESCAPED_UNICODE));
        $pivotBytesAfter = strlen(json_encode($clean, JSON_UNESCAPED_UNICODE));

        $this->artisan('price-list-imports:compact-category-evidence', ['--dry-run' => true, '--chunk' => 1])
            ->expectsOutputToContain('Dry run: no records will be updated.')
            ->expectsOutputToContain("price_list_import_rows: scanned=1 changed=1 bytes_before={$rowBytesBefore} bytes_after={$rowBytesAfter} bytes_saved=".($rowBytesBefore - $rowBytesAfter))
            ->expectsOutputToContain('medicine_product_category: scanned=1 changed=1 bytes_before='.strlen($pivotBefore['evidence'])." bytes_after={$pivotBytesAfter}")
            ->assertSuccessful();

        $this->assertSame($rowBefore, $row->fresh()->getRawOriginal());
        $this->assertSame($pivotBefore, (array) DB::table('medicine_product_category')->find($pivotId));
    }

    public function test_global_cleanup_preserves_metadata_commits_per_batch_and_is_idempotent(): void
    {
        [$first, $firstPivot, $clean] = $this->corruptedEvidence(Organization::factory()->wholesaler()->create());
        [$second, $secondPivot] = $this->corruptedEvidence(Organization::factory()->wholesaler()->create());
        $unchanged = PriceListImportRow::factory()->for($first->import, 'import')->create(['category_candidates' => [$clean]]);
        $unchangedBefore = $unchanged->fresh()->getRawOriginal();
        $firstUpdatedAt = $first->getRawOriginal('updated_at');
        $pivotBefore = (array) DB::table('medicine_product_category')->find($firstPivot);
        $scans = [];
        $reads = [];
        $transactionLevel = DB::transactionLevel();
        $commits = 0;
        DB::listen(function (QueryExecuted $query) use (&$scans, &$reads): void {
            if (str_starts_with($query->sql, 'select') && (str_contains($query->sql, 'from "price_list_import_rows"') || str_contains($query->sql, 'from "medicine_product_category"'))) {
                if (str_starts_with($query->sql, 'select "id" from')) {
                    $scans[] = $query->sql;
                } else {
                    $reads[] = [$query, $query->connection->transactionLevel()];
                }
            }
        });
        Event::listen(TransactionCommitted::class, function () use (&$commits): void {
            $commits++;
        });

        $this->artisan('price-list-imports:compact-category-evidence', ['--chunk' => 1])
            ->expectsOutputToContain('price_list_import_rows: scanned=3 changed=2')
            ->expectsOutputToContain('medicine_product_category: scanned=2 changed=2')
            ->assertSuccessful();

        $this->assertSame(5, $commits);
        $this->assertCount(7, $scans);
        foreach ($scans as $query) {
            $this->assertStringContainsString('limit 1', $query);
        }
        $this->assertCount(5, $reads);
        foreach ($reads as [$query, $readTransactionLevel]) {
            $this->assertStringContainsString('where "id" in (?)', $query->sql);
            $this->assertStringContainsString('order by "id" asc', $query->sql);
            $this->assertCount(1, $query->bindings);
            $this->assertSame($transactionLevel + 1, $readTransactionLevel);
        }
        foreach ([$first, $second] as $row) {
            $this->assertSame([$clean], $row->fresh()->category_candidates);
            $this->assertSame(['candidates' => [$clean], 'issues' => []], $row->fresh()->categorization_evidence);
            $this->assertSame('matched', $row->fresh()->categorization_status);
            $this->assertSame(['syrup'], $row->fresh()->assigned_categories);
        }
        foreach ([$firstPivot, $secondPivot] as $id) {
            $this->assertSame($clean, json_decode(DB::table('medicine_product_category')->find($id)->evidence, true));
        }
        unset($pivotBefore['evidence']);
        $pivotAfter = (array) DB::table('medicine_product_category')->find($firstPivot);
        unset($pivotAfter['evidence']);
        $this->assertSame($pivotBefore, $pivotAfter);
        $this->assertSame($firstUpdatedAt, $first->fresh()->getRawOriginal('updated_at'));
        $this->assertSame($unchangedBefore, $unchanged->fresh()->getRawOriginal());
        $rowBeforeSecondRun = $first->fresh()->getRawOriginal();

        $this->artisan('price-list-imports:compact-category-evidence', ['--dry-run' => true, '--chunk' => 2])
            ->expectsOutputToContain('price_list_import_rows: scanned=3 changed=0')
            ->expectsOutputToContain('medicine_product_category: scanned=2 changed=0')
            ->assertSuccessful();
        $this->artisan('price-list-imports:compact-category-evidence', ['--chunk' => 2])
            ->expectsOutputToContain('price_list_import_rows: scanned=3 changed=0')
            ->expectsOutputToContain('medicine_product_category: scanned=2 changed=0')
            ->assertSuccessful();
        $this->assertSame($rowBeforeSecondRun, $first->fresh()->getRawOriginal());
    }

    public function test_supplier_filter_does_not_change_other_suppliers(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        [$included, $includedPivot, $clean] = $this->corruptedEvidence($supplier);
        [$excluded, $excludedPivot] = $this->corruptedEvidence(Organization::factory()->wholesaler()->create());
        $excludedBefore = $excluded->fresh()->getRawOriginal();
        $excludedPivotBefore = (array) DB::table('medicine_product_category')->find($excludedPivot);

        $this->artisan('price-list-imports:compact-category-evidence', ['--supplier' => $supplier->id, '--chunk' => 1])
            ->expectsOutputToContain('price_list_import_rows: scanned=1 changed=1')
            ->expectsOutputToContain('medicine_product_category: scanned=1 changed=1')
            ->assertSuccessful();

        $this->assertSame([$clean], $included->fresh()->category_candidates);
        $this->assertSame($clean, json_decode(DB::table('medicine_product_category')->find($includedPivot)->evidence, true));
        $this->assertSame($excludedBefore, $excluded->fresh()->getRawOriginal());
        $this->assertSame($excludedPivotBefore, (array) DB::table('medicine_product_category')->find($excludedPivot));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_cleanup_uses_classification_changes_made_after_scanning(bool $dryRun): void
    {
        [$row, $pivotId] = $this->corruptedEvidence(Organization::factory()->wholesaler()->create());
        $clean = ['code' => 'tablets', 'confidence' => 99, 'decision' => 'accepted', 'matching' => ['mode' => 'manual']];
        $current = $clean;
        $current['matching']['evidence'] = ['evidence' => 'previous matching'];
        $current['evidence'] = ['evidence' => 'previous classification'];
        $rowChanges = [
            'category_candidates' => json_encode([$current]),
            'categorization_evidence' => json_encode(['assignments' => [$current], 'issues' => ['reviewed']]),
            'assigned_categories' => '["tablets"]',
            'categorization_status' => 'manual',
            'updated_at' => '2026-10-30 12:00:00',
        ];
        $pivotChanges = [
            'product_category_id' => ProductCategory::query()->where('code', 'tablets')->sole()->id,
            'source' => 'manual',
            'confidence' => 99,
            'evidence' => json_encode($current),
            'updated_at' => '2026-10-30 12:00:00',
        ];
        $this->afterInitialScan('price_list_import_rows', function () use ($row, $rowChanges): void {
            DB::table('price_list_import_rows')->where('id', $row->id)->update($rowChanges);
        });
        $this->afterInitialScan('medicine_product_category', function () use ($pivotId, $pivotChanges): void {
            DB::table('medicine_product_category')->where('id', $pivotId)->update($pivotChanges);
        });
        $rowBytesBefore = strlen($rowChanges['category_candidates']) + strlen($rowChanges['categorization_evidence']);
        $cleanCandidates = json_encode([$clean]);
        $cleanRowEvidence = json_encode(['assignments' => [$clean], 'issues' => ['reviewed']]);
        $rowBytesAfter = strlen($cleanCandidates) + strlen($cleanRowEvidence);
        $pivotBytesBefore = strlen($pivotChanges['evidence']);
        $cleanPivotEvidence = json_encode($clean);
        $pivotBytesAfter = strlen($cleanPivotEvidence);

        $this->artisan('price-list-imports:compact-category-evidence', ['--dry-run' => $dryRun, '--chunk' => 1])
            ->expectsOutputToContain("price_list_import_rows: scanned=1 changed=1 bytes_before={$rowBytesBefore} bytes_after={$rowBytesAfter} bytes_saved=".($rowBytesBefore - $rowBytesAfter))
            ->expectsOutputToContain("medicine_product_category: scanned=1 changed=1 bytes_before={$pivotBytesBefore} bytes_after={$pivotBytesAfter} bytes_saved=".($pivotBytesBefore - $pivotBytesAfter))
            ->assertSuccessful();

        $this->assertDatabaseHas('price_list_import_rows', ['id' => $row->id, ...$rowChanges,
            'category_candidates' => $dryRun ? $rowChanges['category_candidates'] : $cleanCandidates,
            'categorization_evidence' => $dryRun ? $rowChanges['categorization_evidence'] : $cleanRowEvidence,
        ]);
        $this->assertDatabaseHas('medicine_product_category', ['id' => $pivotId, ...$pivotChanges,
            'evidence' => $dryRun ? $pivotChanges['evidence'] : $cleanPivotEvidence,
        ]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_cleanup_skips_deleted_records_and_continues_to_later_batches(bool $dryRun): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        [$deleted, $deletedPivot] = $this->corruptedEvidence($supplier);
        [$remaining, $remainingPivot, $clean] = $this->corruptedEvidence($supplier);
        $rowBefore = $remaining->fresh()->getRawOriginal();
        $pivotBefore = (array) DB::table('medicine_product_category')->find($remainingPivot);
        $this->afterInitialScan('price_list_import_rows', function () use ($deleted): void {
            DB::table('price_list_import_rows')->where('id', $deleted->id)->delete();
        });
        $this->afterInitialScan('medicine_product_category', function () use ($deletedPivot): void {
            DB::table('medicine_product_category')->where('id', $deletedPivot)->delete();
        });
        $rowBytesBefore = strlen($rowBefore['category_candidates']) + strlen($rowBefore['categorization_evidence']);
        $cleanCandidates = json_encode([$clean], JSON_UNESCAPED_UNICODE);
        $cleanRowEvidence = json_encode(['candidates' => [$clean], 'issues' => []], JSON_UNESCAPED_UNICODE);
        $rowBytesAfter = strlen($cleanCandidates) + strlen($cleanRowEvidence);
        $pivotBytesBefore = strlen($pivotBefore['evidence']);
        $cleanPivotEvidence = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $pivotBytesAfter = strlen($cleanPivotEvidence);

        $this->artisan('price-list-imports:compact-category-evidence', ['--dry-run' => $dryRun, '--chunk' => 1])
            ->expectsOutputToContain("price_list_import_rows: scanned=1 changed=1 bytes_before={$rowBytesBefore} bytes_after={$rowBytesAfter} bytes_saved=".($rowBytesBefore - $rowBytesAfter))
            ->expectsOutputToContain("medicine_product_category: scanned=1 changed=1 bytes_before={$pivotBytesBefore} bytes_after={$pivotBytesAfter} bytes_saved=".($pivotBytesBefore - $pivotBytesAfter))
            ->assertSuccessful();

        $this->assertModelMissing($deleted);
        $this->assertDatabaseMissing('medicine_product_category', ['id' => $deletedPivot]);
        $this->assertSame([...$rowBefore,
            'category_candidates' => $dryRun ? $rowBefore['category_candidates'] : $cleanCandidates,
            'categorization_evidence' => $dryRun ? $rowBefore['categorization_evidence'] : $cleanRowEvidence,
        ], $remaining->fresh()->getRawOriginal());
        $this->assertSame([...$pivotBefore,
            'evidence' => $dryRun ? $pivotBefore['evidence'] : $cleanPivotEvidence,
        ], (array) DB::table('medicine_product_category')->find($remainingPivot));
    }

    public function test_cleanup_preserves_json_objects_and_leaves_clean_json_formatting_untouched(): void
    {
        $row = PriceListImportRow::factory()->create();
        DB::table('price_list_import_rows')->where('id', $row->id)->update([
            'category_candidates' => '[ { "code": "syrup", "metadata": {} } ]',
            'categorization_evidence' => '{"assignments":[{"code":"syrup","matching":{},"evidence":"old"}],"issues":[]}',
        ]);

        $this->artisan('price-list-imports:compact-category-evidence')->assertSuccessful();

        $this->assertSame('[ { "code": "syrup", "metadata": {} } ]', $row->fresh()->getRawOriginal('category_candidates'));
        $this->assertSame('{"assignments":[{"code":"syrup","matching":{}}],"issues":[]}', $row->fresh()->getRawOriginal('categorization_evidence'));
    }

    public function test_invalid_batch_or_supplier_options_are_rejected(): void
    {
        $this->artisan('price-list-imports:compact-category-evidence', ['--chunk' => 0])->assertExitCode(2);
        $this->artisan('price-list-imports:compact-category-evidence', ['--supplier' => 'not-an-id'])->assertExitCode(2);
    }

    private function afterInitialScan(string $table, Closure $callback): void
    {
        $handled = false;
        DB::listen(function (QueryExecuted $query) use ($table, $callback, &$handled): void {
            if (! $handled && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "'.$table.'"') && str_contains($query->sql, 'limit')) {
                $handled = true;
                $callback();
            }
        });
    }

    /** @return array{PriceListImportRow, int, array<string, mixed>} */
    private function corruptedEvidence(Organization $supplier): array
    {
        $clean = ['code' => 'syrup', 'label' => 'Сироп', 'confidence' => 93, 'decision' => 'accepted', 'keyword' => 'сироп', 'source_text' => 'Сироп детский', 'span' => [0, 5], 'matching' => ['mode' => 'token_phrase']];
        $corrupted = $clean;
        $corrupted['matching']['evidence'] = ['evidence' => ['old' => true]];
        $corrupted['evidence'] = json_encode(['evidence' => json_encode(['evidence' => str_repeat('old', 100)])]);
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create();
        $row = PriceListImportRow::factory()->for($import, 'import')->create([
            'category_candidates' => [$corrupted], 'categorization_evidence' => ['candidates' => [$corrupted], 'issues' => []],
            'assigned_categories' => ['syrup'], 'categorization_status' => 'matched',
        ]);
        $medicine = Medicine::factory()->for($supplier, 'supplier')->create();
        $pivotId = DB::table('medicine_product_category')->insertGetId([
            'medicine_id' => $medicine->id, 'product_category_id' => ProductCategory::query()->where('code', 'syrup')->sole()->id,
            'source' => 'automatic', 'confidence' => 93, 'evidence' => json_encode($corrupted, JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$row, $pivotId, $clean];
    }
}
