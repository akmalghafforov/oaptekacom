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
        Schema::table('supplier_synchronizations', function (Blueprint $table): void {
            $table->string('selected_source')->nullable();
            $table->json('source_conversion')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_synchronizations', function (Blueprint $table): void {
            $table->dropColumn(['selected_source', 'source_conversion']);
        });
    }
};
