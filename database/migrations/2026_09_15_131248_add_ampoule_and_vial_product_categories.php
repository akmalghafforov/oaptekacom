<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();
        DB::table('product_categories')->upsert([
            ['code' => 'ampoules', 'label' => 'Ампулы', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'vials', 'label' => 'Флаконы', 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['label', 'sort_order', 'is_active', 'updated_at']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('product_categories')->whereIn('code', ['ampoules', 'vials'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('medicine_product_category')->whereColumn('medicine_product_category.product_category_id', 'product_categories.id'))
            ->delete();
    }
};
