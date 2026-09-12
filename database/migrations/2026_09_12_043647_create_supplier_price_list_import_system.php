<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_import_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_organization_id')->unique()->constrained('organizations')->restrictOnDelete();
            $table->string('name')->default('Основной профиль');
            $table->string('file_type')->default('xlsx');
            $table->boolean('is_active')->default(true);
            $table->json('configuration');
            $table->json('sample_metadata')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::rename('price_list_versions', 'price_list_imports');
        Schema::table('medicines', function (Blueprint $table) {
            $table->string('normalized_name')->nullable()->index();
            $table->string('country_of_origin')->nullable();
            $table->string('unit_of_measure')->nullable();
        });
        Schema::table('price_list_imports', function (Blueprint $table) {
            $table->renameColumn('organization_id', 'supplier_organization_id');
            $table->renameColumn('source_path', 'file_path');
            $table->renameColumn('published_by', 'activated_by');
            $table->renameColumn('published_at', 'activated_at');
        });
        Schema::table('price_list_imports', function (Blueprint $table) {
            $table->foreignId('supplier_import_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->json('profile_snapshot')->nullable();
            $table->string('source_type')->default('manual');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('sender_email')->nullable();
            $table->string('message_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inventory_at')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->text('failure_message')->nullable();
            $table->unique(['supplier_organization_id', 'sha256'], 'supplier_import_checksum_unique');
            $table->index(['supplier_organization_id', 'status']);
        });
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->string('supplier_sku')->nullable();
            $table->string('normalized_sku')->nullable();
            $table->string('original_name');
            $table->string('normalized_name');
            $table->string('match_key');
            $table->timestamps();
            $table->unique(['supplier_organization_id', 'match_key']);
            $table->index(['supplier_organization_id', 'normalized_sku']);
        });
        Schema::create('supplier_product_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('supplier_product_id')->constrained()->restrictOnDelete();
            $table->string('normalized_name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['supplier_organization_id', 'normalized_name']);
        });
        Schema::create('supplier_sender_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('email');
            $table->string('normalized_email')->unique();
            $table->timestamps();
        });
        Schema::create('price_list_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_row');
            $table->json('raw_values')->nullable();
            $table->json('parsed_values')->nullable();
            $table->string('disposition');
            $table->string('planned_action')->nullable();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('medicine_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->string('offer_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['price_list_import_id', 'source_row']);
            $table->index(['price_list_import_id', 'disposition']);
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreignId('active_price_list_import_id')->nullable()->constrained('price_list_imports')->restrictOnDelete();
        });
        Schema::table('offers', function (Blueprint $table) {
            $table->renameColumn('price_list_version_id', 'price_list_import_id');
            $table->foreignId('supplier_product_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('source_name')->nullable();
            $table->decimal('quantity', 15, 3)->nullable();
            $table->string('batch')->nullable();
            $table->decimal('total_value', 15, 2)->nullable();
            $table->string('imported_unit')->nullable();
            $table->index(['organization_id', 'price_list_import_id', 'is_active'], 'offers_current_import_index');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropIndex('offers_current_import_index');
            $table->dropConstrainedForeignId('supplier_product_id');
            $table->dropColumn(['source_row', 'source_name', 'quantity', 'batch', 'total_value', 'imported_unit']);
            $table->renameColumn('price_list_import_id', 'price_list_version_id');
        });
        Schema::table('organizations', fn (Blueprint $table) => $table->dropConstrainedForeignId('active_price_list_import_id'));
        Schema::dropIfExists('price_list_import_rows');
        Schema::dropIfExists('supplier_sender_addresses');
        Schema::dropIfExists('supplier_product_aliases');
        Schema::dropIfExists('supplier_products');
        Schema::table('price_list_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_import_profile_id');
            $table->dropColumn(['profile_snapshot', 'source_type', 'original_filename', 'mime_type', 'file_size', 'sha256', 'sender_email', 'message_id', 'received_at', 'inventory_at', 'initiated_by', 'processing_started_at', 'previewed_at', 'failed_at', 'superseded_at', 'total_rows', 'valid_rows', 'error_rows', 'warning_rows', 'skipped_rows', 'failure_message']);
            $table->renameColumn('supplier_organization_id', 'organization_id');
            $table->renameColumn('file_path', 'source_path');
            $table->renameColumn('activated_by', 'published_by');
            $table->renameColumn('activated_at', 'published_at');
        });
        Schema::dropIfExists('supplier_import_profiles');
        Schema::rename('price_list_imports', 'price_list_versions');
        Schema::table('medicines', fn (Blueprint $table) => $table->dropColumn(['normalized_name', 'country_of_origin', 'unit_of_measure']));
    }
};
