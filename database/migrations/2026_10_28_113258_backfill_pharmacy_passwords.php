<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'pharmacy')->orderBy('id')->chunkById(200, function (Collection $users): void {
            foreach ($users as $user) {
                $password = DB::table('one_time_passwords')
                    ->where('phone', $user->phone)
                    ->where('account_type', 'pharmacy')
                    ->whereIn('purpose', ['login', 'registration'])
                    ->whereNotNull('sent_at')
                    ->whereNotIn('status', ['failed', 'pending'])
                    ->orderByDesc('sent_at')->orderByDesc('id')->value('code_hash');
                DB::table('users')->where('id', $user->id)->update(['password' => $password]);
            }
        });
    }

    public function down(): void {}
};
