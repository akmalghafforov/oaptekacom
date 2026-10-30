<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Jobs\FinalizePriceListImportPreview;
use App\Jobs\MaterializePriceListImportChunk;
use App\Models\PriceListImport;
use App\Models\PriceListImportRow;
use App\Models\ProductCategoryCandidate;
use App\Services\PriceList\CategoryCandidateExtractor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class FinalizePriceListImportPreviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_summary_uses_bounded_batches_and_preserves_all_counts(): void
    {
        Queue::fake([MaterializePriceListImportChunk::class]);
        $import = PriceListImport::factory()->create(['status' => PriceListImportStatus::Processing, 'total_rows' => 205, 'summary' => ['existing' => 'preserved']]);
        PriceListImportRow::factory()->count(205)->for($import, 'import')->create([
            'assigned_category' => 'Сироп', 'assigned_categories' => ['syrup'], 'categorization_status' => 'matched',
            'category_candidates' => [['code' => 'syrup', 'decision' => 'accepted'], ['code' => 'solution', 'decision' => 'suppressed']],
        ]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_starts_with($query->sql, 'select "id", "assigned_categories", "category_candidates"')) {
                $queries[] = $query->sql;
            }
        });

        (new FinalizePriceListImportPreview($import))->handle(app(CategoryCandidateExtractor::class));

        $fresh = $import->fresh();
        $this->assertSame(PriceListImportStatus::Preview, $fresh->status);
        $this->assertSame(205, $fresh->valid_rows);
        $this->assertSame('preserved', $fresh->summary['existing']);
        $this->assertSame(['Сироп' => 205], $fresh->summary['category_distribution']);
        $this->assertSame([1 => 205], $fresh->summary['category_count_distribution']);
        $this->assertSame(['matched' => 205], $fresh->summary['categorization']);
        $this->assertSame(205, $fresh->summary['suppressed_match_count']);
        $this->assertCount(3, $queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString('limit 100', $query);
        }
        Queue::assertPushed(MaterializePriceListImportChunk::class, 1);
    }

    public function test_finalization_exception_leaves_processing_and_a_retry_finishes(): void
    {
        config()->set('queue.default', 'database');
        $import = PriceListImport::factory()->create(['status' => PriceListImportStatus::Processing, 'total_rows' => 1]);
        PriceListImportRow::factory()->for($import, 'import')->create();
        $failOnce = true;
        DB::listen(function (QueryExecuted $query) use (&$failOnce): void {
            if ($failOnce && str_starts_with($query->sql, 'insert into "jobs"')) {
                $failOnce = false;
                throw new RuntimeException('Injected dispatch failure');
            }
        });
        $job = new FinalizePriceListImportPreview($import);

        try {
            $job->handle(app(CategoryCandidateExtractor::class));
            $this->fail('Finalization should propagate the exception for a queue retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected dispatch failure', $exception->getMessage());
        }

        $this->assertSame(PriceListImportStatus::Processing, $import->fresh()->status);
        $this->assertNull($import->fresh()->previewed_at);
        $this->assertDatabaseCount('jobs', 0);

        $job->handle(app(CategoryCandidateExtractor::class));

        $this->assertSame(PriceListImportStatus::Preview, $import->fresh()->status);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_candidate_extraction_failure_is_retryable_and_does_not_duplicate_counts(): void
    {
        Queue::fake([MaterializePriceListImportChunk::class]);
        $import = PriceListImport::factory()->create(['status' => PriceListImportStatus::Processing, 'total_rows' => 6]);
        foreach (range(1, 6) as $number) {
            PriceListImportRow::factory()->for($import, 'import')->create([
                'source_row' => $number, 'categorization_status' => 'uncategorized',
                'original_product_name' => 'Лекарство серия '.$number,
            ]);
        }
        $candidateInserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$candidateInserts): void {
            if (str_starts_with($query->sql, 'insert into "product_category_candidates"') && ++$candidateInserts === 2) {
                throw new RuntimeException('Injected extraction failure');
            }
        });
        $job = new FinalizePriceListImportPreview($import);

        try {
            $job->handle(app(CategoryCandidateExtractor::class));
            $this->fail('Extraction should propagate the exception for a queue retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected extraction failure', $exception->getMessage());
        }

        $this->assertSame(PriceListImportStatus::Processing, $import->fresh()->status);
        $this->assertNull($import->fresh()->previewed_at);
        $this->assertDatabaseCount('product_category_candidates', 1);
        Queue::assertNothingPushed();

        $job->handle(app(CategoryCandidateExtractor::class));

        $this->assertSame(PriceListImportStatus::Preview, $import->fresh()->status);
        $this->assertDatabaseCount('product_category_candidates', 2);
        $candidate = ProductCategoryCandidate::query()->where('normalized_phrase', 'лекарство')->sole();
        $this->assertSame(6, $candidate->occurrences);
        $this->assertSame(['Лекарство серия 1', 'Лекарство серия 2', 'Лекарство серия 3', 'Лекарство серия 4', 'Лекарство серия 5'], $candidate->examples);
        Queue::assertPushed(MaterializePriceListImportChunk::class, 1);
    }

    public function test_exhausted_finalization_retries_explicitly_fail_the_import(): void
    {
        $import = PriceListImport::factory()->create(['status' => PriceListImportStatus::Processing]);

        (new FinalizePriceListImportPreview($import))->failed(new RuntimeException('finalization failed'));

        $this->assertSame(PriceListImportStatus::Failed, $import->fresh()->status);
        $this->assertSame('parsing', $import->fresh()->failure_stage);
        $this->assertNotNull($import->fresh()->failed_at);
    }
}
