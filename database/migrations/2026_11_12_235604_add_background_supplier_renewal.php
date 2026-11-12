<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_synchronizations', function (Blueprint $table): void {
            $table->string('selection_mode')->nullable();
        });
        Schema::table('supplier_sync_runs', function (Blueprint $table): void {
            $table->timestamp('mailbox_wait_started_at')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->json('decision')->nullable();
        });
        Schema::table('supplier_sync_attempts', function (Blueprint $table): void {
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
        });
        Schema::create('external_service_states', function (Blueprint $table): void {
            $table->string('source')->primary();
            $table->string('status')->default('ready');
            $table->string('reason')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamp('next_request_at', 6)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('directory_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->json('directory')->nullable();
            $table->json('directory_progress')->nullable();
            $table->uuid('directory_owner')->nullable();
            $table->timestamp('directory_lease_expires_at')->nullable();
            $table->timestamps();
        });
        foreach (['icorp', 'pricelist_tj', 'email'] as $source) {
            DB::table('external_service_states')->insert(['source' => $source, 'status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('external_download_leases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('owner')->nullable();
            $table->timestamp('next_request_at')->nullable();
            $table->unsignedBigInteger('fence')->default(0);
            $table->timestamp('expires_at')->nullable();
        });
        DB::table('external_download_leases')->insert(['id' => 1]);
        Schema::create('external_availabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_supplier_partner_id')->constrained()->cascadeOnDelete();
            $table->char('version_key', 64);
            $table->boolean('available');
            $table->timestamp('published_at')->nullable();
            $table->string('date_precision')->nullable();
            $table->json('evidence');
            $table->timestamp('last_checked_at');
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamp('next_check_at');
            $table->unique(['external_supplier_partner_id', 'version_key'], 'external_availability_version_unique');
        });
        Schema::create('external_downloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_sync_attempt_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('external_availability_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('source');
            $table->string('external_id');
            $table->char('version_key', 64);
            $table->string('status')->default('pending');
            $table->unsignedInteger('next_page')->default(1);
            $table->unsignedInteger('received_rows')->default(0);
            $table->unsignedInteger('total_rows')->nullable();
            $table->unsignedInteger('total_pages')->nullable();
            $table->json('snapshot');
            $table->json('company')->nullable();
            $table->json('account_discount')->nullable();
            $table->uuid('worker_token')->nullable();
            $table->timestamp('worker_expires_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['supplier_organization_id', 'source', 'version_key'], 'external_download_version_unique');
        });
        Schema::create('external_download_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_download_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page');
            $table->json('items');
            $table->unique(['external_download_id', 'page']);
        });
        Schema::create('external_service_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->string('reason');
            $table->string('active_source')->nullable()->unique();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('notification_attempted_at')->nullable();
            $table->string('notification_error')->nullable();
            $table->json('delivered_to')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['external_service_incidents', 'external_download_pages', 'external_downloads', 'external_availabilities', 'external_download_leases', 'external_service_states'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('supplier_sync_attempts', fn (Blueprint $table) => $table->dropColumn(['retry_count', 'next_retry_at']));
        Schema::table('supplier_sync_runs', fn (Blueprint $table) => $table->dropColumn(['mailbox_wait_started_at', 'next_retry_at', 'decision']));
        Schema::table('supplier_synchronizations', fn (Blueprint $table) => $table->dropColumn('selection_mode'));
    }
};
