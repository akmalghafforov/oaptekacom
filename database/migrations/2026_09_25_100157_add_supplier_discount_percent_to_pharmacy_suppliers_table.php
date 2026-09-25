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
        Schema::table('pharmacy_suppliers', function (Blueprint $table): void {
            $table->decimal('supplier_discount_percent', 5, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pharmacy_suppliers', function (Blueprint $table): void {
            $table->dropColumn('supplier_discount_percent');
        });
    }
};
