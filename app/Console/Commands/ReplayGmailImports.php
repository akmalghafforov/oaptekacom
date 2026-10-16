<?php

namespace App\Console\Commands;

use App\Models\GmailImportReceipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReplayGmailImports extends Command
{
    protected $signature = 'gmail-imports:replay {--receipt=} {--supplier=} {--from=} {--to=}';

    protected $description = 'Explicitly reconsider skipped or intake-failed receipts';

    public function handle(): int
    {
        if (! $this->option('receipt') && (! $this->option('supplier') || ! $this->option('from') || ! $this->option('to'))) {
            $this->error('Provide --receipt or --supplier with --from and --to.');

            return self::FAILURE;
        }
        $query = GmailImportReceipt::whereNull('price_list_import_id')->where(function ($query): void {
            $query->whereIn('status', ['skipped', 'failed']);
            if ($this->option('receipt')) {
                $query->orWhere(fn ($query) => $query->where('receipt_scope', 'message')->where('status', 'routed'));
            }
        });
        if ($this->option('receipt')) {
            $query->whereKey($this->option('receipt'));
        } else {
            $query->where('supplier_organization_id', $this->option('supplier'))->whereBetween('received_at', [$this->option('from'), $this->option('to')]);
        }
        $count = 0;
        $query->chunkById(100, function ($receipts) use (&$count): void {
            foreach ($receipts as $receipt) {
                DB::transaction(function () use ($receipt): void {
                    $receipt = GmailImportReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
                    if ($receipt->price_list_import_id || ! in_array($receipt->status, ['skipped', 'failed', 'routed'], true)) {
                        return;
                    }
                    if ($receipt->receipt_scope === 'message') {
                        $receipt->children()->whereIn('status', ['skipped', 'failed'])->whereNull('price_list_import_id')->update(['status' => 'skipped', 'reason' => 'replay_requested', 'attempts' => 0, 'next_attempt_at' => null]);
                    }
                    $receipt->update(['status' => 'pending', 'reason' => null, 'attempts' => 0, 'next_attempt_at' => null]);
                });
                $count++;
            }
        });
        $this->info("Reconsidering {$count} receipts on the next synchronization.");

        return self::SUCCESS;
    }
}
