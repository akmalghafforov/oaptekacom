<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->foreignId('linked_pharmacy_organization_id')->nullable()->unique()->constrained('organizations')->restrictOnDelete();
            $table->boolean('setup_required')->default(false);
        });
        Schema::create('user_role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->foreignId('organization_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_role_assignments');
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('linked_pharmacy_organization_id');
            $table->dropColumn('setup_required');
        });
    }
};
