<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_list_import_rows', function (Blueprint $table): void {
            $table->index('offer_id');
        });
    }

    public function down(): void
    {
        Schema::table('price_list_import_rows', function (Blueprint $table): void {
            $table->dropIndex('price_list_import_rows_offer_id_index');
        });
    }
};
