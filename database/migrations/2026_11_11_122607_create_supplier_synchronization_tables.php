<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_supplier_partners', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->string('external_id');
            $table->string('name');
            $table->json('phones');
            $table->json('metadata')->nullable();
            $table->timestamp('discovered_at');
            $table->foreignId('supplier_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->json('profile_configuration')->nullable();
            $table->boolean('profile_active')->default(false);
            $table->unsignedInteger('profile_version')->default(1);
            $table->timestamps();
            $table->unique(['source', 'external_id']);
            $table->unique(['supplier_organization_id', 'source'], 'supplier_external_service_unique');
        });
        Schema::create('supplier_synchronizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_organization_id')->unique()->constrained('organizations')->restrictOnDelete();
            $table->boolean('automatic_enabled')->default(false);
            $table->boolean('settings_confirmed')->default(false);
            $table->boolean('paused')->default(false);
            $table->unsignedInteger('fetch_interval_minutes')->default(15);
            $table->unsignedInteger('freshness_hours')->default(24);
            $table->json('sources');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('next_check_at')->nullable()->index();
            $table->timestamp('last_activated_at')->nullable();
            $table->timestamps();
        });
        Schema::create('supplier_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('active_supplier_id')->nullable()->unique()->constrained('organizations')->restrictOnDelete();
            $table->string('trigger');
            $table->string('status')->default('queued');
            $table->json('configuration_snapshot');
            $table->string('selected_source')->nullable();
            $table->string('reason')->nullable();
            $table->uuid('owner_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('supplier_sync_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_sync_run_id')->constrained()->restrictOnDelete();
            $table->string('source');
            $table->string('status')->default('fetching');
            $table->string('reason')->nullable();
            $table->foreignId('price_list_import_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['supplier_sync_run_id', 'source']);
        });
        Schema::table('price_list_imports', function (Blueprint $table): void {
            $table->timestamp('source_published_at')->nullable();
            $table->string('source_date_precision')->nullable();
            $table->char('source_fingerprint', 64)->nullable();
            $table->json('source_metadata')->nullable();
            $table->index(['supplier_organization_id', 'source_type', 'source_fingerprint'], 'supplier_source_fingerprint_index');
        });
    }

    public function down(): void
    {
        Schema::table('price_list_imports', function (Blueprint $table): void {
            $table->dropIndex('supplier_source_fingerprint_index');
            $table->dropColumn(['source_published_at', 'source_date_precision', 'source_fingerprint', 'source_metadata']);
        });
        Schema::dropIfExists('supplier_sync_attempts');
        Schema::dropIfExists('supplier_sync_runs');
        Schema::dropIfExists('supplier_synchronizations');
        Schema::dropIfExists('external_supplier_partners');
    }
};
