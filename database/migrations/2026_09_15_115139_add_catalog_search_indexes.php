<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX medicines_search_text_trgm_index ON medicines USING gin (search_text gin_trgm_ops)');
        DB::statement('CREATE INDEX offers_medicine_price_id_index ON offers (medicine_id, price, id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS medicines_search_text_trgm_index');
        DB::statement('DROP INDEX IF EXISTS offers_medicine_price_id_index');
    }
};
