<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->string('purchase_scope')->nullable();
            $table->string('condition_match')->nullable();
            $table->decimal('minimum_total', 16, 2)->nullable();
            $table->decimal('minimum_order_value', 16, 2)->nullable();
            $table->unsignedInteger('minimum_order_count')->nullable();
        });
        Schema::create('promotion_prizes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
        });
        Schema::create('promotion_contributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('pharmacy_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->decimal('amount', 20, 2);
            $table->timestamps();
            $table->unique(['promotion_id', 'order_id']);
            $table->index(['promotion_id', 'pharmacy_organization_id'], 'promotion_contributions_pharmacy_index');
        });
        Schema::create('promotion_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('pharmacy_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->timestamp('qualified_at');
            $table->boolean('is_eligible');
            $table->decimal('qualifying_total', 20, 2);
            $table->decimal('largest_order_value', 20, 2);
            $table->unsignedInteger('qualifying_order_count');
            $table->timestamps();
            $table->unique(['promotion_id', 'pharmacy_organization_id'], 'promotion_participants_pharmacy_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_participants');
        Schema::dropIfExists('promotion_contributions');
        Schema::dropIfExists('promotion_prizes');
        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropColumn(['purchase_scope', 'condition_match', 'minimum_total', 'minimum_order_value', 'minimum_order_count']);
        });
    }
};
