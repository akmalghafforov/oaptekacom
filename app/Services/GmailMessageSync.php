<?php

namespace App\Services;

use App\Models\GmailConnection;
use App\Models\GmailMessage;
use Google\Service\Exception as GoogleException;
use Google\Service\Gmail;
use Illuminate\Support\Carbon;

class GmailMessageSync
{
    public function __construct(private GmailMailboxAccess $access, private GmailMessageReader $reader) {}

    /** Persist one complete page before advancing its durable checkpoint. */
    public function sync(int $connectionId): bool
    {
        return $this->access->run(function (Gmail $gmail, GmailConnection $connection): bool {
            if (! $connection->scan_completed_at) {
                if (! $connection->scan_baseline) {
                    $connection->update(['scan_baseline' => $gmail->users->getProfile('me')->getHistoryId()]);
                }
                try {
                    $page = $gmail->users_messages->listUsersMessages('me', array_filter(['labelIds' => ['INBOX'], 'q' => 'has:attachment', 'maxResults' => 50, 'pageToken' => $connection->scan_page]));
                } catch (GoogleException $exception) {
                    if ($exception->getCode() !== 400 || ! $connection->scan_page) {
                        throw $exception;
                    }
                    $connection->update(['scan_page' => null]);

                    return true;
                }
                foreach ($page->getMessages() ?? [] as $message) {
                    $this->save($gmail, $connection, $message->getId());
                }
                $connection->update(['scan_page' => $page->getNextPageToken(), 'last_sync_error' => null]);
                if (! $page->getNextPageToken()) {
                    $connection->update(['scan_completed_at' => now(), 'history_cursor' => $connection->scan_baseline, 'history_page' => null]);
                }

                return true;
            }
            try {
                $page = $gmail->users_history->listUsersHistory('me', array_filter(['startHistoryId' => $connection->history_cursor, 'pageToken' => $connection->history_page, 'maxResults' => 50, 'historyTypes' => ['messageAdded', 'labelAdded']]));
            } catch (GoogleException $exception) {
                if ($exception->getCode() !== 404) {
                    throw $exception;
                }
                $connection->update(['scan_completed_at' => null, 'scan_baseline' => null, 'scan_page' => null, 'history_page' => null, 'history_cursor' => null]);

                return true;
            }
            $ids = [];
            foreach ($page->getHistory() ?? [] as $history) {
                foreach ($history->getMessagesAdded() ?? [] as $added) {
                    $ids[$added->getMessage()->getId()] = true;
                }
                foreach ($history->getLabelsAdded() ?? [] as $added) {
                    if (in_array('INBOX', $added->getLabelIds() ?? [], true)) {
                        $ids[$added->getMessage()->getId()] = true;
                    }
                }
            }
            foreach (array_keys($ids) as $id) {
                $this->save($gmail, $connection, (string) $id);
            }
            $checkpoint = ['history_page' => $page->getNextPageToken(), 'last_sync_error' => null];
            if (! $page->getNextPageToken()) {
                $checkpoint += ['history_cursor' => $page->getHistoryId() ?: $connection->history_cursor, 'last_synced_at' => now(), 'scan_baseline' => null];
            }
            $connection->update($checkpoint);

            return (bool) $page->getNextPageToken();
        }, $connectionId);
    }

    private function save(Gmail $gmail, GmailConnection $connection, string $id): void
    {
        $identity = ['user_id' => $connection->user_id, 'mailbox_email' => strtolower($connection->email), 'gmail_message_id' => $id];
        if (GmailMessage::where($identity)->exists()) {
            return;
        }
        try {
            $message = $gmail->users_messages->get('me', $id, ['format' => 'full']);
        } catch (GoogleException $exception) {
            if ($exception->getCode() === 404) {
                return;
            }
            throw $exception;
        }
        if (! in_array('INBOX', $message->getLabelIds() ?? [], true) || ! $message->getPayload() || ! $this->reader->attachments($message->getPayload())) {
            return;
        }
        $data = $this->reader->read($message, fn (string $attachment): string => $gmail->users_messages_attachments->get('me', $id, $attachment)->getData() ?? '');
        $receivedAt = $message->getInternalDate() ? Carbon::createFromTimestampMs((int) $message->getInternalDate()) : now();
        GmailMessage::firstOrCreate($identity, ['received_at' => $receivedAt, 'sender' => $data['from'], 'subject' => $data['subject'], 'snippet' => $data['snippet'], 'unread' => $data['unread'], 'attachment_manifest' => $data['attachments'], 'plain_body' => $data['plain'], 'html_body' => $data['html'], 'synced_at' => now()]);
    }
}
