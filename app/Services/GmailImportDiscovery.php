<?php

namespace App\Services;

use App\Jobs\ProcessGmailImportReceipt;
use App\Models\GmailConnection;
use App\Models\GmailImportReceipt;
use Google\Service\Exception as GoogleException;
use Google\Service\Gmail;
use Illuminate\Support\Facades\Cache;

class GmailImportDiscovery
{
    public function __construct(private GmailMailboxAccess $access) {}

    public function sync(): void
    {
        $newReceiptIds = [];
        try {
            $this->access->run(function (Gmail $gmail, GmailConnection $connection) use (&$newReceiptIds): void {
                try {
                    $page = $gmail->users_messages->listUsersMessages('me', array_filter(['labelIds' => ['INBOX'], 'q' => 'has:attachment', 'maxResults' => 100, 'pageToken' => $connection->scan_page]));
                } catch (GoogleException $exception) {
                    if ($exception->getCode() !== 400 || ! $connection->scan_page) {
                        throw $exception;
                    }
                    $connection->update(['scan_page' => null, 'scan_completed_at' => null]);
                    $page = $gmail->users_messages->listUsersMessages('me', ['labelIds' => ['INBOX'], 'q' => 'has:attachment', 'maxResults' => 100]);
                }
                foreach ($page->getMessages() ?? [] as $message) {
                    $newReceiptIds[] = $this->record($connection, $message->getId());
                }
                $connection->update(['scan_page' => $page->getNextPageToken()]);
                if (! $page->getNextPageToken()) {
                    $connection->update(['scan_completed_at' => now(), 'last_synced_at' => now(), 'last_sync_error' => null]);
                }
            });
        } finally {
            foreach (array_filter($newReceiptIds) as $id) {
                ProcessGmailImportReceipt::dispatch($id)->afterCommit();
            }
        }
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
