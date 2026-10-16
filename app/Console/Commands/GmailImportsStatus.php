<?php

namespace App\Console\Commands;

use App\Models\GmailConnection;
use App\Models\GmailImportReceipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class GmailImportsStatus extends Command
{
    protected $signature = 'gmail-imports:status';

    protected $description = 'Show Gmail synchronization health and receipt outcomes';

    public function handle(): int
    {
        if (! Schema::hasTable('gmail_import_receipts') || ! Schema::hasColumn('gmail_import_receipts', 'receipt_scope')) {
            $this->warn('Gmail import migrations are pending.');

            return self::FAILURE;
        }
        $connection = GmailConnection::find(config('gmail-imports.connection_id'));
        $this->table(['Enabled', 'Mailbox', 'Connection', 'Scan complete', 'Last sync', 'Retry after', 'Error'], [[config('gmail-imports.enabled') ? 'yes' : 'no', $connection?->email, $connection?->status, $connection?->scan_completed_at, $connection?->last_synced_at, $connection?->next_sync_at, $connection?->last_sync_error]]);
        $this->table(['Scope', 'Status', 'Reason', 'Count'], GmailImportReceipt::selectRaw('receipt_scope, status, reason, count(*) as total')->groupBy('receipt_scope', 'status', 'reason')->get()->map(fn ($receipt): array => [$receipt->receipt_scope, $receipt->status, $receipt->reason, $receipt->total])->all());

        return self::SUCCESS;
    }
}
