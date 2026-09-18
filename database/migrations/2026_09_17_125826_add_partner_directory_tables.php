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
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('whatsapp_phone')->nullable();
            $table->json('additional_phones')->nullable();
        });

        Schema::create('pharmacy_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pharmacy_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->timestamps();
            $table->unique(['pharmacy_organization_id', 'supplier_organization_id']);
        });

        Schema::create('supplier_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('redeemed_by_pharmacy_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_invitations');
        Schema::dropIfExists('pharmacy_suppliers');
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['contact_name', 'contact_email', 'whatsapp_phone', 'additional_phones']));
    }
};
