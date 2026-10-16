<?php

namespace App\Services;

use App\Jobs\ProcessGmailImportReceipt;
use App\Models\GmailConnection;
use App\Models\GmailImportReceipt;
use Google\Service\Exception as GoogleException;
use Google\Service\Gmail;

class GmailImportDiscovery
{
    public function __construct(private GmailMailboxAccess $access) {}

    public function sync(): void
    {
        $newReceiptIds = [];
        try {
            $this->access->run(function (Gmail $gmail, GmailConnection $connection) use (&$newReceiptIds): void {
                if (! $connection->history_cursor) {
                    if (! $connection->scan_baseline) {
                        $connection->update(['scan_baseline' => $gmail->users->getProfile('me')->getHistoryId()]);
                    }
                    try {
                        $page = $gmail->users_messages->listUsersMessages('me', array_filter(['labelIds' => ['INBOX'], 'maxResults' => 100, 'pageToken' => $connection->scan_page]));
                    } catch (GoogleException $exception) {
                        if ($exception->getCode() !== 400 || ! $connection->scan_page) {
                            throw $exception;
                        }
                        $connection->update(['scan_baseline' => null, 'scan_page' => null, 'scan_completed_at' => null]);

                        return;
                    }
                    foreach ($page->getMessages() ?? [] as $message) {
                        $newReceiptIds[] = $this->record($connection, $message->getId());
                    }
                    $connection->update(['scan_page' => $page->getNextPageToken()]);
                    if ($page->getNextPageToken()) {
                        return;
                    }
                    $connection->update(['history_cursor' => $connection->scan_baseline, 'scan_completed_at' => now()]);
                }
                try {
                    $page = $gmail->users_history->listUsersHistory('me', array_filter(['startHistoryId' => $connection->history_cursor, 'maxResults' => 100, 'pageToken' => $connection->history_page]));
                } catch (GoogleException $exception) {
                    if ($exception->getCode() === 400 && $connection->history_page) {
                        $connection->update(['history_page' => null]);

                        return;
                    }
                    if ($exception->getCode() !== 404) {
                        throw $exception;
                    }
                    $connection->update(['history_cursor' => null, 'scan_baseline' => null, 'scan_page' => null, 'history_page' => null, 'scan_completed_at' => null]);

                    return;
                }
                foreach ($page->getHistory() ?? [] as $history) {
                    foreach ($history->getMessagesAdded() ?? [] as $added) {
                        $newReceiptIds[] = $this->record($connection, $added->getMessage()->getId());
                    }
                    foreach ($history->getLabelsAdded() ?? [] as $added) {
                        if (in_array('INBOX', $added->getLabelIds() ?? [], true)) {
                            $newReceiptIds[] = $this->record($connection, $added->getMessage()->getId());
                        }
                    }
                }
                $connection->update(['history_page' => $page->getNextPageToken()]);
                if (! $page->getNextPageToken()) {
                    $connection->update(['history_cursor' => $page->getHistoryId(), 'last_synced_at' => now(), 'last_sync_error' => null]);
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

        return $receipt->wasRecentlyCreated ? $receipt->id : null;
    }
}
