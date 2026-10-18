<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('gmail_connections')->update([
            'history_cursor' => null, 'history_page' => null, 'scan_baseline' => null,
            'scan_page' => null, 'scan_completed_at' => null, 'last_synced_at' => null,
            'next_sync_at' => null,
        ]);
        DB::table('gmail_import_receipts')->where('receipt_scope', 'message')
            ->where('status', 'pending')->whereNull('price_list_import_id')
            ->whereNull('supplier_organization_id')->whereNull('parent_receipt_id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('gmail_import_receipts as children')
                    ->whereColumn('children.parent_receipt_id', 'gmail_import_receipts.id');
            })->select('id')->chunkById(100, function (Collection $receipts): void {
                DB::table('gmail_import_receipts')->whereIn('id', $receipts->pluck('id'))
                    ->where('status', 'pending')
                    ->update(['status' => 'skipped', 'reason' => 'attachment_search_required', 'next_attempt_at' => null]);
            });
    }

    public function down(): void {}
};
