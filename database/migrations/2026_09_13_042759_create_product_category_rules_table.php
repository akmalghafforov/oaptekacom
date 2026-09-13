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
        Schema::create('product_category_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_category_rule_set_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('literal_keyword');
            $table->string('normalized_matcher');
            $table->string('matcher_mode')->default('token_phrase');
            $table->unsignedSmallInteger('priority');
            $table->unsignedTinyInteger('confidence')->default(90);
            $table->json('context_requirements')->nullable();
            $table->json('context_exclusions')->nullable();
            $table->json('supersedes_categories')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->index(['product_category_rule_set_id', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_category_rules');
    }
};
