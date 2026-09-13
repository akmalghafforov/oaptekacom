<?php

namespace App\Services\PriceList;

use App\Models\ProductCategoryRuleSet;
use Database\Seeders\ProductCategoryRuleSetSeeder;
use Illuminate\Support\Facades\DB;

class ProductCategoryRuleSetResolver
{
    public function current(): ProductCategoryRuleSet
    {
        return DB::transaction(fn (): ProductCategoryRuleSet => $this->createInitial());
    }

    private function createInitial(): ProductCategoryRuleSet
    {
        app(ProductCategoryRuleSetSeeder::class)->run();

        return ProductCategoryRuleSet::query()->where('status', 'published')->latest('version')->firstOrFail();
    }
}
