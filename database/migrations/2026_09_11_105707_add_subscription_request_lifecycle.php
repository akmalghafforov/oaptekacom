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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('payment_request_id')->nullable()->unique()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->change();
            $table->date('starts_on')->nullable()->change();
            $table->date('ends_on')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_request_id');
        });
    }
};
