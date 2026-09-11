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
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('amount');
            $table->string('recipient_wallet')->nullable()->after('payment_method');
            $table->text('payment_instructions')->nullable()->after('recipient_wallet');
            $table->string('transfer_reference')->nullable()->after('receipt_path');
            $table->date('transferred_on')->nullable()->after('transfer_reference');
            $table->decimal('verified_amount', 12, 2)->nullable()->after('transferred_on');
            $table->string('verified_reference')->nullable()->after('verified_amount');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['payment_method', 'recipient_wallet', 'payment_instructions', 'transfer_reference', 'transferred_on', 'verified_amount', 'verified_reference', 'reviewed_at', 'rejection_reason']);
        });
    }
};
