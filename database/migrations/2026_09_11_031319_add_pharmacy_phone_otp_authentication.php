<?php

use App\Support\PhoneNormalizer;
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
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });

        Schema::create('one_time_passwords', function (Blueprint $table) {
            $table->id();
            $table->string('purpose');
            $table->string('phone');
            $table->string('code_hash');
            $table->uuid('transaction_id')->unique();
            $table->string('provider_transaction_id')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
            $table->index(['purpose', 'phone', 'status']);
            $table->index('expires_at');
        });

        $pharmacies = DB::table('users')->where('role', 'pharmacy')->orderBy('id')->get();
        DB::table('users')->where('role', 'pharmacy')->update(['phone' => null, 'password' => null, 'password_change_required' => false]);
        $seen = [];
        $pharmacies->each(function (object $user) use (&$seen): void {
            $phone = PhoneNormalizer::normalize($user->phone);
            $blocked = $phone === null || isset($seen[$phone]);
            if ($phone !== null) {
                $seen[$phone] = true;
            }
            DB::table('users')->where('id', $user->id)->update(['phone' => $blocked ? null : $phone, 'password' => null, 'password_change_required' => false, 'is_blocked' => $blocked ? true : $user->is_blocked]);
            if (! $blocked && $user->organization_id) {
                DB::table('organizations')->where('id', $user->organization_id)->update(['phone' => $phone]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('one_time_passwords');
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
