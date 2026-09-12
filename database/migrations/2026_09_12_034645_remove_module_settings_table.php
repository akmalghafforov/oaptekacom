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
        DB::table('audit_events')
            ->where('event', 'module.updated')
            ->orWhere('subject_type', 'App\\Models\\ModuleSetting')
            ->delete();

        Schema::dropIfExists('module_settings');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('module_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        $timestamp = now();

        DB::table('module_settings')->insert(array_map(
            fn (string $key): array => [
                'key' => $key,
                'enabled' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            ['catalog', 'orders', 'subscription', 'supplier_offers', 'barter', 'finance'],
        ));
    }
};
