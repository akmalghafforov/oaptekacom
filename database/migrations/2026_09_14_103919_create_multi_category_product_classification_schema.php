<?php

use App\Enums\ProductCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label')->unique();
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        $now = now();
        DB::table('product_categories')->insert(collect(ProductCategory::ordered())->reject(fn (ProductCategory $category): bool => $category === ProductCategory::Unrecognized)->values()->map(fn (ProductCategory $category, int $order): array => ['code' => $category->code(), 'label' => $category->value, 'sort_order' => $order, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now])->all());

        Schema::table('medicines', function (Blueprint $table) {
            $table->timestamp('categories_locked_at')->nullable()->after('category_assigned_at');
            $table->foreignId('categories_locked_by')->nullable()->after('categories_locked_at')->constrained('users')->nullOnDelete();
        });
        Schema::create('medicine_product_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained()->restrictOnDelete();
            $table->string('source');
            $table->unsignedTinyInteger('confidence');
            $table->foreignId('rule_set_id')->nullable()->constrained('product_category_rule_sets')->nullOnDelete();
            $table->char('rule_set_checksum', 64)->nullable();
            $table->json('evidence')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['medicine_id', 'product_category_id']);
            $table->index(['product_category_id', 'medicine_id']);
        });
        Schema::table('product_category_rules', function (Blueprint $table) {
            $table->foreignId('product_category_id')->nullable()->after('product_category_rule_set_id')->constrained()->nullOnDelete();
        });
        DB::table('product_category_rules')->orderBy('id')->each(function (object $rule): void {
            $categoryId = DB::table('product_categories')->where('label', $rule->category)->value('id');
            DB::table('product_category_rules')->where('id', $rule->id)->update(['product_category_id' => $categoryId]);
        });
        Schema::table('price_list_import_rows', function (Blueprint $table) {
            $table->json('assigned_categories')->nullable()->after('assigned_category');
            $table->json('category_candidates')->nullable()->after('categorization_evidence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('price_list_import_rows', fn (Blueprint $table) => $table->dropColumn(['assigned_categories', 'category_candidates']));
        Schema::table('product_category_rules', fn (Blueprint $table) => $table->dropConstrainedForeignId('product_category_id'));
        Schema::dropIfExists('medicine_product_category');
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categories_locked_by');
            $table->dropColumn('categories_locked_at');
        });
        Schema::dropIfExists('product_categories');
    }
};
