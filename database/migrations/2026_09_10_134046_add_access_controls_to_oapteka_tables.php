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
        $unknownRoles = DB::table('users')->whereNotIn('role', ['pharmacy', 'wholesaler', 'admin'])->pluck('role')->unique();
        if ($unknownRoles->isNotEmpty()) {
            throw new RuntimeException('Unknown user roles: '.$unknownRoles->implode(', ').'. Run oapteka:audit-access before migrating.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('active_trade_mode')->nullable()->after('role');
            $table->boolean('password_change_required')->default(false)->after('password');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('totp_secret');
            $table->json('recovery_codes')->nullable()->after('two_factor_confirmed_at');
        });

        Schema::table('audit_events', function (Blueprint $table) {
            $table->json('before')->nullable()->after('meta');
            $table->json('after')->nullable()->after('before');
        });

        Schema::create('module_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        foreach (['catalog', 'orders', 'subscription', 'supplier_offers', 'barter', 'finance'] as $key) {
            DB::table('module_settings')->insert(['key' => $key, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_settings');
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropColumn(['before', 'after']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['active_trade_mode', 'password_change_required', 'two_factor_confirmed_at', 'recovery_codes']);
        });
    }
};
