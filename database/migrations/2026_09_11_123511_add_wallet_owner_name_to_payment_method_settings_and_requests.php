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
        Schema::table('payment_method_settings', function (Blueprint $table) {
            $table->string('wallet_owner_name')->nullable()->after('wallet_number');
        });
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('recipient_wallet_owner_name')->nullable()->after('recipient_wallet');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_method_settings', function (Blueprint $table) {
            $table->dropColumn('wallet_owner_name');
        });
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn('recipient_wallet_owner_name');
        });
    }
};
