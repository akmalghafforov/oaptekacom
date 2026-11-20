<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('supplier_import_profiles')->select('supplier_organization_id')->groupBy('supplier_organization_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Multiple legacy profiles found. Confirm deployed inbox and manual precedence before migrating.');
        }

        $hasUniqueIndex = Schema::hasIndex('supplier_import_profiles', 'supplier_import_profiles_supplier_organization_id_unique', 'unique');
        $hasLookupIndex = Schema::hasIndex('supplier_import_profiles', 'supplier_import_profiles_supplier_organization_id_index');
        Schema::table('supplier_import_profiles', function (Blueprint $table) use ($hasUniqueIndex, $hasLookupIndex): void {
            if (! $hasLookupIndex) {
                $table->index('supplier_organization_id');
            }
            if ($hasUniqueIndex) {
                $table->dropUnique(['supplier_organization_id']);
            }
            $table->boolean('inbox_enabled')->default(false);
            $table->boolean('manual_enabled')->default(false);
        });
        if (DB::getDriverName() === 'sqlite') {
            // Add the references inline so SQLite does not rebuild the referenced organizations table.
            DB::statement('ALTER TABLE organizations ADD COLUMN inbox_import_profile_id INTEGER REFERENCES supplier_import_profiles(id) ON DELETE SET NULL');
            DB::statement('ALTER TABLE organizations ADD COLUMN manual_import_profile_id INTEGER REFERENCES supplier_import_profiles(id) ON DELETE SET NULL');
        } else {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->foreignId('inbox_import_profile_id')->nullable()->constrained('supplier_import_profiles')->nullOnDelete();
                $table->foreignId('manual_import_profile_id')->nullable()->constrained('supplier_import_profiles')->nullOnDelete();
            });
        }

        DB::table('supplier_import_profiles')->where('is_active', true)->update(['inbox_enabled' => true, 'manual_enabled' => true]);
        DB::table('supplier_import_profiles')->select('id', 'supplier_organization_id', 'is_active')->chunkById(500, function ($profiles): void {
            foreach ($profiles as $profile) {
                DB::table('organizations')->where('id', $profile->supplier_organization_id)->update([
                    'inbox_import_profile_id' => $profile->id,
                    'manual_import_profile_id' => $profile->is_active ? $profile->id : null,
                ]);
            }
        });
    }

    public function down(): void
    {
        if (DB::table('supplier_import_profiles')->exists()) {
            throw new RuntimeException('Profile defaults require a forward fix after migration.');
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->dropColumn(['inbox_import_profile_id', 'manual_import_profile_id']);
            });
        } else {
            Schema::table('organizations', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('inbox_import_profile_id');
                $table->dropConstrainedForeignId('manual_import_profile_id');
            });
        }
        Schema::table('supplier_import_profiles', function (Blueprint $table): void {
            $table->unique('supplier_organization_id');
            $table->dropIndex(['supplier_organization_id']);
            $table->dropColumn(['inbox_enabled', 'manual_enabled']);
        });
    }
};
