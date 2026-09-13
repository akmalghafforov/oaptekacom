<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('price_list_imports', function (Blueprint $table): void {
            $table->foreignId('product_category_rule_set_id')->nullable()->after('supplier_import_profile_id')->constrained()->nullOnDelete();
            $table->char('product_category_rule_set_checksum', 64)->nullable()->after('product_category_rule_set_id');
            $table->json('effective_layout')->nullable()->after('profile_snapshot');
        });
        Schema::table('price_list_import_rows', function (Blueprint $table): void {
            $table->string('source_filename')->nullable()->after('source_row');
            $table->string('source_worksheet')->nullable();
            $table->text('original_product_name')->nullable();
            $table->text('normalized_product_name')->nullable();
            $table->string('assigned_category')->nullable();
            $table->string('matched_keyword')->nullable();
            $table->text('matched_source_text')->nullable();
            $table->unsignedTinyInteger('categorization_confidence')->nullable();
            $table->string('categorization_status')->nullable();
            $table->json('categorization_evidence')->nullable();
            $table->index(['price_list_import_id', 'categorization_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('price_list_import_rows', function (Blueprint $table): void {
            $table->dropIndex(['price_list_import_id', 'categorization_status']);
            $table->dropColumn(['source_filename', 'source_worksheet', 'original_product_name', 'normalized_product_name', 'assigned_category', 'matched_keyword', 'matched_source_text', 'categorization_confidence', 'categorization_status', 'categorization_evidence']);
        });
        Schema::table('price_list_imports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_category_rule_set_id');
            $table->dropColumn(['product_category_rule_set_checksum', 'effective_layout']);
        });
    }
};
