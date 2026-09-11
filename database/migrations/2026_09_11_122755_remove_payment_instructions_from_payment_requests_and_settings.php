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
            $table->dropColumn('instructions');
        });
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn('payment_instructions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_method_settings', function (Blueprint $table) {
            $table->text('instructions')->nullable();
        });
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->text('payment_instructions')->nullable();
        });
    }
};
