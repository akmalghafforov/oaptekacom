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
        Schema::create('supplier_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('supplier_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('buyer_name');
            $table->string('supplier_name');
            $table->unsignedInteger('item_count');
            $table->decimal('total', 12, 2);
            $table->string('shared_via');
            $table->timestamp('shared_at');
            $table->timestamps();

            $table->index(['user_id', 'shared_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_requests');
    }
};
