<?php

use App\Enums\UserRole;
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
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_phone_unique');
            $table->unique(['phone', 'role']);
        });

        Schema::table('one_time_passwords', function (Blueprint $table) {
            $table->string('account_type')->nullable()->after('purpose');
        });

        DB::table('one_time_passwords')
            ->whereNull('consumed_at')
            ->update(['status' => 'invalidated', 'consumed_at' => now()]);
        DB::table('one_time_passwords')
            ->whereNull('account_type')
            ->update(['account_type' => UserRole::Pharmacy->value]);

        Schema::table('one_time_passwords', function (Blueprint $table) {
            $table->string('account_type')->nullable(false)->change();
            $table->index(['purpose', 'account_type', 'phone', 'status'], 'otp_account_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('one_time_passwords', function (Blueprint $table) {
            $table->dropIndex('otp_account_lookup');
            $table->dropColumn('account_type');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_phone_role_unique');
            $table->unique('phone');
        });
    }
};
