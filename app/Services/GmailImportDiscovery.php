<?php

namespace App\Services;

use App\Jobs\ProcessGmailImportReceipt;
use App\Models\GmailConnection;
use App\Models\GmailImportReceipt;
use App\Models\GmailMessage;
use Illuminate\Support\Facades\Cache;

class GmailImportDiscovery
{
    public function __construct(private GmailMailboxAccess $access) {}

    public function sync(): bool
    {
        $connection = $this->access->connection();
        $messages = GmailMessage::where('user_id', $connection->user_id)
            ->where('mailbox_email', strtolower($connection->email))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('gmail_import_receipts')
                    ->whereColumn('gmail_import_receipts.mailbox_email', 'gmail_messages.mailbox_email')
                    ->whereColumn('gmail_import_receipts.gmail_message_id', 'gmail_messages.gmail_message_id')
                    ->where('receipt_scope', 'message')
                    ->where(fn ($query) => $query->where('status', '!=', 'skipped')->orWhereNull('reason')->orWhere('reason', '!=', 'attachment_search_required'));
            })->orderBy('id')->limit(100)->get(['gmail_message_id']);
        $messages->each(function ($message) use ($connection): void {
            if ($id = $this->record($connection, $message->gmail_message_id)) {
                ProcessGmailImportReceipt::dispatch($id)->afterCommit();
            }
        });

        return $messages->count() === 100;
    }

    private function record(GmailConnection $connection, string $id): ?int
    {
        $receipt = GmailImportReceipt::firstOrCreate(['mailbox_email' => strtolower($connection->email), 'gmail_message_id' => $id, 'receipt_scope' => 'message']);

        if ($receipt->wasRecentlyCreated) {
            return $receipt->id;
        }
        if ($receipt->status !== 'skipped' || $receipt->reason !== 'attachment_search_required') {
            return null;
        }
        $lock = Cache::store(config('session.block_store'))->lock('gmail-receipt:'.$receipt->id, 120);
        if (! $lock->get()) {
            return null;
        }
        try {
            $receipt->refresh();
            if ($receipt->status !== 'skipped' || $receipt->reason !== 'attachment_search_required') {
                return null;
            }
            $receipt->update(['status' => 'pending', 'reason' => null, 'attempts' => 0, 'next_attempt_at' => null]);

            return $receipt->id;
        } finally {
            $lock->release();
        }
    }
}
