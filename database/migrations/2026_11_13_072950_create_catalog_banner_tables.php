<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_banners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('title');
            $table->text('description');
            $table->string('whatsapp_number', 15);
            $table->text('whatsapp_message')->nullable();
            $table->string('desktop_image');
            $table->string('tablet_image');
            $table->string('mobile_image');
            $table->unsignedSmallInteger('duration_seconds')->default(8);
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->timestamps();
        });
        Schema::create('catalog_banner_rotation_counters', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('position')->default(0);
        });
        DB::table('catalog_banner_rotation_counters')->insert(['id' => 1, 'position' => 0]);
        Schema::create('catalog_banner_rotations', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->json('remaining');
            $table->json('cycle_ids');
            $table->unsignedBigInteger('position');
            $table->unsignedBigInteger('cycle')->default(0);
            $table->unsignedBigInteger('last_banner_id')->nullable();
        });
        Schema::create('catalog_banner_appearances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalog_banner_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->index();
            $table->timestamp('counted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_banner_appearances');
        Schema::dropIfExists('catalog_banner_rotations');
        Schema::dropIfExists('catalog_banner_rotation_counters');
        Schema::dropIfExists('catalog_banners');
    }
};
