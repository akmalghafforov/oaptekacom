<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_import_workflow_states', function (Blueprint $table): void {
            $table->string('workflow')->primary();
            $table->string('status');
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->date('successful_price_list_day')->nullable();
            $table->boolean('external_fetch_enabled')->default(true);
            $table->boolean('external_fetch_overridden')->default(false);
            $table->string('external_fetch_exclusion_reason')->nullable();
            $table->json('external_fetch_evidence')->nullable();
            $table->timestamp('external_fetch_assessed_at')->nullable();
        });
        Schema::create('supplier_version_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->date('published_day');
            $table->string('source');
            $table->string('state')->default('reserved');
            $table->foreignId('gmail_import_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('external_download_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('price_list_import_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_organization_id', 'published_day'], 'supplier_published_day_unique');
        });
        Schema::create('supplier_email_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('mailbox_email');
            $table->string('gmail_message_id');
            $table->timestamp('received_at')->index();
            $table->date('published_day');
            $table->string('date_origin');
            $table->string('date_precision');
            $table->json('manifest');
            $table->timestamps();
            $table->unique(['mailbox_email', 'gmail_message_id', 'supplier_organization_id'], 'supplier_email_delivery_unique');
        });
        Schema::create('external_listing_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->timestamp('scan_hour');
            $table->string('status')->default('pending');
            $table->boolean('dispatch_requested')->default(false);
            $table->json('pages')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['source', 'scan_hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_import_workflow_states');
        Schema::dropIfExists('external_listing_runs');
        Schema::dropIfExists('supplier_email_deliveries');
        Schema::dropIfExists('supplier_version_claims');
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['successful_price_list_day', 'external_fetch_enabled', 'external_fetch_overridden', 'external_fetch_exclusion_reason', 'external_fetch_evidence', 'external_fetch_assessed_at']));
    }
};
