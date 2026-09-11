<?php

use App\Support\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $suppliers = DB::table('users')->where('role', 'wholesaler')->orderBy('id')->get();
        $seen = DB::table('users')->where('role', '!=', 'wholesaler')->whereNotNull('phone')->pluck('phone')->flip()->all();

        foreach ($suppliers as $supplier) {
            $phone = PhoneNormalizer::normalize($supplier->phone);
            $blocked = $phone === null || isset($seen[$phone]);
            if ($phone !== null) {
                $seen[$phone] = true;
            }

            DB::table('users')->where('id', $supplier->id)->update([
                'phone' => $blocked ? null : $phone,
                'password' => null,
                'password_change_required' => false,
                'is_blocked' => $blocked ? true : $supplier->is_blocked,
            ]);

            if (! $blocked && $supplier->organization_id) {
                DB::table('organizations')->where('id', $supplier->organization_id)->update(['phone' => $phone]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
