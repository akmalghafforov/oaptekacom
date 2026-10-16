<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_import_profiles', function (Blueprint $table): void {
            $table->string('attachment_filename_filter')->nullable();
        });
        Schema::table('supplier_sender_addresses', function (Blueprint $table): void {
            $table->dropUnique(['normalized_email']);
            $table->unique(['normalized_email', 'supplier_organization_id'], 'supplier_sender_pair_unique');
        });
        Schema::table('gmail_import_receipts', function (Blueprint $table): void {
            $table->foreignId('parent_receipt_id')->nullable()->constrained('gmail_import_receipts')->restrictOnDelete();
            $table->string('receipt_scope')->default('message');
            $table->dropUnique('gmail_receipt_message_unique');
            $table->unique(['mailbox_email', 'gmail_message_id', 'receipt_scope'], 'gmail_receipt_scope_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('supplier_sender_addresses')->select('normalized_email')->groupBy('normalized_email')->havingRaw('count(*) > 1')->exists()
            || DB::table('gmail_import_receipts')->where('receipt_scope', '!=', 'message')->exists()) {
            throw new RuntimeException('Cannot roll back supplier routing while shared sender addresses or supplier receipts exist.');
        }
        Schema::table('gmail_import_receipts', function (Blueprint $table): void {
            $table->dropUnique('gmail_receipt_scope_unique');
            $table->dropForeign(['parent_receipt_id']);
            $table->dropColumn(['parent_receipt_id', 'receipt_scope']);
            $table->unique(['mailbox_email', 'gmail_message_id'], 'gmail_receipt_message_unique');
        });
        Schema::table('supplier_sender_addresses', function (Blueprint $table): void {
            $table->dropUnique('supplier_sender_pair_unique');
            $table->unique('normalized_email');
        });
        Schema::table('supplier_import_profiles', function (Blueprint $table): void {
            $table->dropColumn('attachment_filename_filter');
        });
    }
};
