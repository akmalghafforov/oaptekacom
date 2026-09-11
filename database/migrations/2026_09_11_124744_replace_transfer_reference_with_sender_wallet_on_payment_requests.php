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
            $table->string('sender_wallet_number')->nullable()->after('recipient_wallet_owner_name');
            $table->dropColumn('transfer_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('transfer_reference')->nullable()->after('receipt_path');
            $table->dropColumn('sender_wallet_number');
        });
    }
};
