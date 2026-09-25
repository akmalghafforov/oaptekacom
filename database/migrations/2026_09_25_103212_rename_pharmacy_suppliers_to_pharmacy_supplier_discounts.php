<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('pharmacy_suppliers')->whereNull('supplier_discount_percent')->delete();

        Schema::dropIfExists('supplier_invitations');
        Schema::rename('pharmacy_suppliers', 'pharmacy_supplier_discounts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('pharmacy_supplier_discounts', 'pharmacy_suppliers');

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
};
