<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Models\Medicine;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Services\PriceList\ActivationDispatcher;
use App\Services\PriceList\ImportProcessor;
use App\Services\PriceList\ProductCategoryRuleSetResolver;
use App\Services\PriceList\ProfileValidator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CategoryEvidenceActivationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_repeated_activation_keeps_candidate_and_pivot_evidence_bounded(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $rules = app(ProductCategoryRuleSetResolver::class)->current();
        $previous = null;
        $candidateSize = $pivotSize = null;
        foreach (range(1, 8) as $cycle) {
            $import = PriceListImport::factory()->for($supplier, 'supplier')->create([
                'status' => PriceListImportStatus::Preview, 'inventory_at' => '2026-09-'.(10 + $cycle),
                'profile_snapshot' => ProfileValidator::defaults(), 'valid_rows' => 1,
                'product_category_rule_set_id' => $rules->id, 'product_category_rule_set_checksum' => $rules->checksum,
            ]);

            app(ImportProcessor::class)->process($import, [2 => ['A' => 'Сироп детский', 'B' => '12.50']]);
            app(ActivationDispatcher::class)->dispatch($import);

            $row = $import->rows()->sole();
            $pivot = json_decode(DB::table('medicine_product_category')->sole()->evidence, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(PriceListImportStatus::Completed, $import->fresh()->status);
            $this->assertSame(['syrup'], $row->assigned_categories);
            $this->assertSame('accepted', $row->category_candidates[0]['decision']);
            $this->assertNoNestedEvidence($row->category_candidates);
            $this->assertNoNestedEvidence($pivot);
            $this->assertLessThan(2048, strlen(json_encode($row->category_candidates)));
            $this->assertLessThan(2048, strlen(json_encode($pivot)));
            if ($cycle > 2) {
                $this->assertSame($candidateSize, strlen(json_encode($row->category_candidates)));
                $this->assertSame($pivotSize, strlen(json_encode($pivot)));
            }
            if ($previous !== null) {
                $this->assertSame(PriceListImportStatus::Superseded, $previous->fresh()->status);
            }
            $previous = $import;
            $candidateSize = strlen(json_encode($row->category_candidates));
            $pivotSize = strlen(json_encode($pivot));
        }
        $this->assertSame(1, Medicine::query()->where('supplier_organization_id', $supplier->id)->count());
    }

    public function test_activation_sanitizes_legacy_candidates_before_persisting_pivots(): void
    {
        $supplier = Organization::factory()->wholesaler()->create();
        $rules = app(ProductCategoryRuleSetResolver::class)->current();
        $import = PriceListImport::factory()->for($supplier, 'supplier')->create([
            'status' => PriceListImportStatus::Preview, 'profile_snapshot' => ProfileValidator::defaults(),
            'product_category_rule_set_id' => $rules->id, 'valid_rows' => 1,
        ]);
        app(ImportProcessor::class)->process($import, [2 => ['A' => 'Сироп детский', 'B' => '12.50']]);
        $row = $import->rows()->sole();
        $candidate = $row->category_candidates[0];
        $candidate['matching_metadata'] = ['keyword' => 'сироп', 'evidence' => ['old' => 'discard']];
        $expected = $candidate;
        unset($expected['matching_metadata']['evidence']);
        $candidate['evidence'] = json_encode(['evidence' => str_repeat('old', 1000)]);
        $row->update(['category_candidates' => [$candidate]]);

        app(ActivationDispatcher::class)->dispatch($import);

        $this->assertSame($expected, json_decode(DB::table('medicine_product_category')->sole()->evidence, true));
    }

    /** @param array<array-key, mixed> $value */
    private function assertNoNestedEvidence(array $value): void
    {
        $this->assertArrayNotHasKey('evidence', $value);
        foreach ($value as $metadata) {
            if (is_array($metadata)) {
                $this->assertNoNestedEvidence($metadata);
            }
        }
    }
}
