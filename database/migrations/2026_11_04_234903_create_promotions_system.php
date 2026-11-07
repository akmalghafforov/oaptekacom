<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('logo_path')->nullable();
        });
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('conditions')->nullable();
            $table->string('city')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('draft');
            $table->unsignedInteger('terms_version')->default(1);
            $table->timestamp('first_published_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('moderation_comment')->nullable();
            $table->timestamps();
            $table->index(['status', 'starts_on', 'ends_on']);
            $table->index(['organization_id', 'status']);
        });
        Schema::create('promotion_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('supplier_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('dosage')->nullable();
            $table->string('form')->nullable();
            $table->string('packaging')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('price_mode')->default('final');
            $table->unsignedInteger('minimum_quantity')->default(1);
            $table->boolean('in_stock')->default(true);
            $table->unsignedInteger('stock')->nullable();
            $table->text('discount_rule')->nullable();
            $table->unsignedInteger('terms_version')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('promotion_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('material');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
        Schema::create('promotion_quote_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('promotion_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->text('message');
            $table->json('contact_snapshot');
            $table->timestamps();
        });
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('offer_id')->nullable()->change();
            $table->foreignId('promotion_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('accepted_terms_version', 64)->nullable();
            $table->unique(['cart_id', 'promotion_item_id']);
        });
        foreach (['order_items', 'supplier_request_items'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('promotion_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('promotion_item_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
        $condition = '(offer_id IS NULL) <> (promotion_item_id IS NULL)';
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $trigger = 'cart_items_source_'.strtolower($event);
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$event} ON cart_items WHEN (NEW.offer_id IS NULL) = (NEW.promotion_item_id IS NULL) BEGIN SELECT RAISE(ABORT, 'Cart item requires exactly one source'); END");
            }
        } else {
            DB::statement("ALTER TABLE cart_items ADD CONSTRAINT cart_items_exactly_one_source CHECK ({$condition})");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS cart_items_source_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS cart_items_source_update');
        } else {
            $clause = DB::getDriverName() === 'mysql' ? 'DROP CHECK' : 'DROP CONSTRAINT';
            DB::statement("ALTER TABLE cart_items {$clause} cart_items_exactly_one_source");
        }
        foreach (['order_items', 'supplier_request_items'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('promotion_item_id');
                $table->dropConstrainedForeignId('promotion_id');
            });
        }
        DB::table('cart_items')->whereNotNull('promotion_item_id')->delete();
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropUnique(['cart_id', 'promotion_item_id']);
            $table->dropConstrainedForeignId('promotion_item_id');
            $table->dropColumn('accepted_terms_version');
            $table->unsignedBigInteger('offer_id')->nullable(false)->change();
        });
        foreach (['promotion_quote_requests', 'promotion_attachments', 'promotion_items', 'promotions'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('logo_path'));
    }
};
