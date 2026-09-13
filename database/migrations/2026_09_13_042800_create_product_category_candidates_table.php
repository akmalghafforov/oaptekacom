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
        Schema::create('product_category_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('price_list_import_id')->constrained()->cascadeOnDelete();
            $table->string('normalized_phrase');
            $table->unsignedInteger('occurrences')->default(0);
            $table->json('examples');
            $table->string('review_status')->default('new');
            $table->string('proposed_category')->nullable();
            $table->timestamps();
            $table->unique(['price_list_import_id', 'normalized_phrase']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_category_candidates');
    }
};
