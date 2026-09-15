<?php

namespace App\Console\Commands;

use App\Models\Medicine;
use App\Models\ProductCategory;
use App\Services\PriceList\ProductCategoryClassifier;
use App\Services\PriceList\ProductCategoryRuleSetResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('catalog:reclassify {--chunk=200 : Medicines processed per chunk}')]
#[Description('Reclassify unlocked catalog medicines with the current published category rules')]
class ReclassifyCatalogMedicines extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ProductCategoryRuleSetResolver $ruleSets, ProductCategoryClassifier $classifier): int
    {
        $ruleSet = $ruleSets->current();
        $processed = 0;
        Medicine::query()->whereNull('categories_locked_at')->chunkById((int) $this->option('chunk'), function ($medicines) use ($classifier, $ruleSet, &$processed): void {
            foreach ($medicines as $medicine) {
                $result = $classifier->classify($medicine->name, $ruleSet, ['form' => $medicine->form, 'dosage' => $medicine->dosage]);
                $categories = ProductCategory::query()->whereIn('code', array_column($result['assignments'], 'code'))->get();
                $candidates = collect($result['assignments'])->keyBy('code');
                $medicine->categories()->sync($categories->mapWithKeys(fn (ProductCategory $category): array => [$category->id => ['source' => 'automatic', 'confidence' => $candidates[$category->code]['confidence'], 'rule_set_id' => $ruleSet->id, 'rule_set_checksum' => $ruleSet->checksum, 'evidence' => json_encode($candidates[$category->code], JSON_UNESCAPED_UNICODE)]])->all());
                $medicine->update(['category' => $result['category'], 'category_status' => $result['status'], 'category_confidence' => $result['confidence'], 'category_evidence' => $result['evidence'], 'category_rule_set_id' => $ruleSet->id, 'category_rule_set_checksum' => $ruleSet->checksum, 'category_assigned_at' => now()]);
                $processed++;
            }
        });
        $this->info("Reclassified {$processed} medicines.");

        return self::SUCCESS;
    }
}
