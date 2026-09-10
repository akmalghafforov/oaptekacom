<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('organizations', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('type'); $t->string('city')->nullable();
            $t->string('phone')->nullable(); $t->string('status')->default('pending'); $t->string('supplier_mode')->default('both');
            $t->decimal('minimum_order', 12, 2)->default(0); $t->text('delivery_conditions')->nullable(); $t->timestamp('subscription_until')->nullable(); $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('organization_id')->nullable()->constrained()->nullOnDelete(); $t->string('phone')->nullable()->unique();
            $t->string('role')->default('pharmacy'); $t->boolean('is_blocked')->default(false); $t->boolean('totp_enabled')->default(false);
            $t->string('totp_secret')->nullable(); $t->string('theme')->default('light'); $t->timestamp('approved_at')->nullable();
        });
        Schema::create('activation_histories', function (Blueprint $t) { $t->id(); $t->string('phone')->unique(); $t->foreignId('organization_id')->nullable()->constrained()->nullOnDelete(); $t->timestamp('demo_used_at')->nullable(); $t->timestamps(); });
        Schema::create('payment_requests', function (Blueprint $t) { $t->id(); $t->foreignId('organization_id')->constrained()->cascadeOnDelete(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->unsignedInteger('days'); $t->decimal('amount',12,2); $t->string('receipt_path')->nullable(); $t->string('status')->default('pending'); $t->text('admin_note')->nullable(); $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps(); });
        Schema::create('medicines', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('inn')->nullable(); $t->string('form')->nullable(); $t->string('dosage')->nullable(); $t->string('manufacturer')->nullable(); $t->string('category')->nullable(); $t->string('search_text'); $t->timestamps(); });
        Schema::create('price_list_versions', function (Blueprint $t) { $t->id(); $t->foreignId('organization_id')->constrained()->cascadeOnDelete(); $t->string('source_path')->nullable(); $t->string('status')->default('draft'); $t->json('summary')->nullable(); $t->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamp('published_at')->nullable(); $t->timestamps(); });
        Schema::create('offers', function (Blueprint $t) { $t->id(); $t->foreignId('organization_id')->constrained()->cascadeOnDelete(); $t->foreignId('medicine_id')->constrained()->cascadeOnDelete(); $t->foreignId('price_list_version_id')->nullable()->constrained()->nullOnDelete(); $t->decimal('price',12,2); $t->decimal('old_price',12,2)->nullable(); $t->unsignedInteger('stock')->default(0); $t->date('expires_at')->nullable(); $t->boolean('is_promotion')->default(false); $t->boolean('is_active')->default(false); $t->timestamps(); });
        Schema::create('carts', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->timestamps(); });
        Schema::create('cart_items', function (Blueprint $t) { $t->id(); $t->foreignId('cart_id')->constrained()->cascadeOnDelete(); $t->foreignId('offer_id')->constrained()->cascadeOnDelete(); $t->unsignedInteger('quantity'); $t->decimal('unit_price',12,2); $t->json('snapshot'); $t->timestamps(); $t->unique(['cart_id','offer_id']); });
        Schema::create('checkouts', function (Blueprint $t) { $t->id(); $t->foreignId('buyer_organization_id')->constrained('organizations'); $t->foreignId('user_id')->constrained(); $t->timestamps(); });
        Schema::create('orders', function (Blueprint $t) { $t->id(); $t->foreignId('checkout_id')->nullable()->constrained()->nullOnDelete(); $t->foreignId('buyer_organization_id')->constrained('organizations'); $t->foreignId('supplier_organization_id')->constrained('organizations'); $t->string('status')->default('new'); $t->decimal('total',12,2)->default(0); $t->timestamps(); });
        Schema::create('order_items', function (Blueprint $t) { $t->id(); $t->foreignId('order_id')->constrained()->cascadeOnDelete(); $t->foreignId('offer_id')->nullable()->constrained()->nullOnDelete(); $t->foreignId('medicine_id')->nullable()->constrained()->nullOnDelete(); $t->unsignedInteger('quantity'); $t->unsignedInteger('confirmed_quantity')->nullable(); $t->decimal('unit_price',12,2); $t->decimal('proposed_price',12,2)->nullable(); $t->json('snapshot'); $t->timestamps(); });
        Schema::create('audit_events', function (Blueprint $t) { $t->id(); $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $t->string('event'); $t->morphs('subject'); $t->json('meta')->nullable(); $t->ipAddress('ip')->nullable(); $t->timestamps(); });
    }
    public function down(): void { foreach (['audit_events','order_items','orders','checkouts','cart_items','carts','offers','price_list_versions','medicines','payment_requests','activation_histories'] as $table) Schema::dropIfExists($table); Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id')); Schema::dropIfExists('organizations'); }
};
