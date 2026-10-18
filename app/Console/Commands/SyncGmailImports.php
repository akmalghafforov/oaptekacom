<?php

namespace App\Console\Commands;

use App\Enums\PriceListImportStatus;
use App\Jobs\PreparePriceListImport;
use App\Jobs\ProcessGmailImportReceipt;
use App\Jobs\SyncGmailMessages;
use App\Models\GmailConnection;
use App\Models\GmailImportReceipt;
use App\Models\PriceListImport;
use App\Services\GmailException;
use App\Services\GmailMailboxAccess;
use App\Services\PriceList\ActivationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SyncGmailImports extends Command
{
    protected $signature = 'gmail-imports:sync';

    protected $description = 'Discover inbox mail and reconcile durable Gmail intake receipts';

    public function handle(): int
    {
        if (! Schema::hasTable('gmail_import_receipts') || ! Schema::hasColumn('gmail_import_receipts', 'receipt_scope')) {
            $this->info('Gmail import migrations are pending. Automatic intake has not started.');

            return config('gmail-imports.enabled') ? self::FAILURE : self::SUCCESS;
        }
        if (Schema::hasTable('gmail_messages')) {
            GmailConnection::with('user')->where('status', 'connected')->each(function ($connection): void {
                if ($connection->user?->isAdmin() && ! $connection->user->is_blocked && (! $connection->next_sync_at || $connection->next_sync_at->isPast())) {
                    SyncGmailMessages::dispatch($connection->id);
                }
            });
        }
        $this->reconcileImports();
        if (! config('gmail-imports.enabled')) {
            $this->info('Automatic Gmail imports are disabled.');

            return self::SUCCESS;
        }
        try {
            $connection = app(GmailMailboxAccess::class)->connection();
        } catch (GmailException) {
            Log::warning('Gmail synchronization suspended: designated connection requires attention', ['connection_id' => config('gmail-imports.connection_id')]);
            $this->error('Designated Gmail connection requires attention.');

            return self::FAILURE;
        }
        if (! $connection->last_synced_at || Carbon::parse($connection->last_synced_at)->lt(now()->subMinutes(15))) {
            Log::warning('Gmail synchronization has not succeeded within 15 minutes', ['connection_id' => $connection->id]);
        }
        \App\Jobs\SyncGmailImports::dispatch();
        GmailImportReceipt::where('mailbox_email', strtolower($connection->email))->where('status', 'pending')->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->where('updated_at', '<', now()->subMinutes(2))->chunkById(100, function ($receipts): void {
            foreach ($receipts as $receipt) {
                ProcessGmailImportReceipt::dispatch($receipt->id);
            }
        });

        return self::SUCCESS;
    }

    private function reconcileImports(): void
    {
        GmailImportReceipt::whereIn('status', ['accepted', 'failed'])->whereNotNull('price_list_import_id')->chunkById(100, function ($receipts): void {
            foreach ($receipts as $receipt) {
                $import = PriceListImport::find($receipt->price_list_import_id);
                if ($receipt->status === 'failed' && $import && $import->status !== PriceListImportStatus::Failed) {
                    $receipt->update(['status' => 'accepted', 'reason' => null]);
                }
                if (in_array($import?->status, [PriceListImportStatus::Completed, PriceListImportStatus::Superseded, PriceListImportStatus::Failed], true)) {
                    $receipt->update(['status' => $import->status === PriceListImportStatus::Failed ? 'failed' : 'completed', 'reason' => $import->status === PriceListImportStatus::Failed ? 'import_failed' : null]);
                } elseif (in_array($import?->status, [PriceListImportStatus::Pending, PriceListImportStatus::Processing], true) && $import->updated_at->lt(now()->subMinutes(15))) {
                    $lock = Cache::lock('laravel-queue-overlap:price-list-stage:'.$import->id, 140);
                    if ($lock->get()) {
                        try {
                            PreparePriceListImport::dispatch($import)->onQueue(config('price-list-imports.queue'));
                        } finally {
                            $lock->release();
                        }
                    }
                } elseif (in_array($import?->status, [PriceListImportStatus::Preview, PriceListImportStatus::Committing], true) && $import->updated_at->lt(now()->subMinutes(15))) {
                    $lock = Cache::lock('laravel-queue-overlap:price-list-stage:'.$import->id, 140);
                    if ($lock->get()) {
                        try {
                            app(ActivationDispatcher::class)->dispatch($import);
                        } finally {
                            $lock->release();
                        }
                    }
                }
            }
        });
    }
}
