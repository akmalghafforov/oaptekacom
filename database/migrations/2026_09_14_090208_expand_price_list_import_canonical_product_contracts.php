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
        if (! Schema::hasColumn('medicines', 'supplier_organization_id')) {
            Schema::table('medicines', function (Blueprint $table) {
                $table->foreignId('supplier_organization_id')->nullable()->after('id')->constrained('organizations')->restrictOnDelete();
                $table->string('category_status')->nullable()->after('category');
                $table->foreignId('category_rule_set_id')->nullable()->after('category_status')->constrained('product_category_rule_sets')->nullOnDelete();
                $table->char('category_rule_set_checksum', 64)->nullable()->after('category_rule_set_id');
                $table->unsignedTinyInteger('category_confidence')->nullable()->after('category_rule_set_checksum');
                $table->json('category_evidence')->nullable()->after('category_confidence');
                $table->timestamp('category_assigned_at')->nullable()->after('category_evidence');
                $table->unique(['supplier_organization_id', 'normalized_name'], 'medicines_supplier_normalized_name_unique');
            });
        }

        if (! Schema::hasIndex('supplier_products', 'supplier_products_medicine_unique')) {
            Schema::table('supplier_products', function (Blueprint $table) {
                $table->unique('medicine_id', 'supplier_products_medicine_unique');
                $table->unique(['supplier_organization_id', 'normalized_name'], 'supplier_products_supplier_name_unique');
            });
        }

        if (! Schema::hasColumn('price_list_imports', 'duplicate_of_import_id')) {
            Schema::table('price_list_imports', function (Blueprint $table) {
                $table->foreignId('duplicate_of_import_id')->nullable()->after('sha256')->constrained('price_list_imports')->restrictOnDelete();
                $table->timestamp('duplicate_confirmed_at')->nullable()->after('duplicate_of_import_id');
                $table->foreignId('duplicate_confirmed_by')->nullable()->after('duplicate_confirmed_at')->constrained('users')->nullOnDelete();
                $table->string('failure_stage')->nullable()->after('failure_message');
            });
        }

        if (Schema::hasIndex('price_list_imports', 'supplier_import_checksum_unique')) {
            Schema::table('price_list_imports', function (Blueprint $table) {
                $table->dropUnique('supplier_import_checksum_unique');
            });
        }
        if (! Schema::hasIndex('price_list_imports', 'supplier_import_checksum_status_index')) {
            Schema::table('price_list_imports', function (Blueprint $table) {
                $table->index(['supplier_organization_id', 'sha256', 'status'], 'supplier_import_checksum_status_index');
                $table->unique(['supplier_organization_id', 'source_type', 'message_id'], 'supplier_import_message_unique');
            });
        }

        if (! Schema::hasIndex('offers', 'offers_import_source_row_unique')) {
            Schema::table('offers', function (Blueprint $table) {
                $table->unique(['price_list_import_id', 'source_row'], 'offers_import_source_row_unique');
                $table->index(['organization_id', 'price_list_import_id', 'expires_at'], 'offers_current_catalog_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropUnique('offers_import_source_row_unique');
            $table->dropIndex('offers_current_catalog_index');
        });
        Schema::table('price_list_imports', function (Blueprint $table) {
            $table->dropUnique('supplier_import_message_unique');
            $table->dropIndex('supplier_import_checksum_status_index');
            $table->unique(['supplier_organization_id', 'sha256'], 'supplier_import_checksum_unique');
            $table->dropConstrainedForeignId('duplicate_confirmed_by');
            $table->dropConstrainedForeignId('duplicate_of_import_id');
            $table->dropColumn(['duplicate_confirmed_at', 'failure_stage']);
        });
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->dropUnique('supplier_products_medicine_unique');
            $table->dropUnique('supplier_products_supplier_name_unique');
        });
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropUnique('medicines_supplier_normalized_name_unique');
            $table->dropConstrainedForeignId('category_rule_set_id');
            $table->dropConstrainedForeignId('supplier_organization_id');
            $table->dropColumn(['category_status', 'category_rule_set_checksum', 'category_confidence', 'category_evidence', 'category_assigned_at']);
        });
    }
};
