<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmail_connections', function (Blueprint $table): void {
            foreach (['history_cursor', 'scan_baseline', 'scan_page', 'history_page'] as $column) {
                $table->text($column)->nullable();
            }
            $table->timestamp('scan_completed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_error')->nullable();
        });
        Schema::create('gmail_import_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('mailbox_email');
            $table->string('gmail_message_id');
            $table->string('sender_email')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('supplier_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('supplier_import_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('price_list_import_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('attachment_manifest')->nullable();
            $table->json('selected_part')->nullable();
            $table->string('status')->default('pending');
            $table->string('reason')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['mailbox_email', 'gmail_message_id'], 'gmail_receipt_message_unique');
            $table->index(['status', 'updated_at']);
        });
        Schema::create('gmail_import_content_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->char('sha256', 64);
            $table->foreignId('price_list_import_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['supplier_organization_id', 'sha256'], 'gmail_content_supplier_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_import_content_claims');
        Schema::dropIfExists('gmail_import_receipts');
        Schema::table('gmail_connections', function (Blueprint $table): void {
            $table->dropColumn(['history_cursor', 'scan_baseline', 'scan_page', 'history_page', 'scan_completed_at', 'last_synced_at', 'last_sync_error']);
        });
    }
};
