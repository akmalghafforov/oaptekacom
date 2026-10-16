<?php

namespace App\Console\Commands;

use App\Services\GmailImportIntake;
use App\Services\GmailMailboxAccess;
use App\Services\GmailMessageReader;
use Google\Service\Gmail;
use Illuminate\Console\Command;

class PreviewGmailImports extends Command
{
    protected $signature = 'gmail-imports:preview';

    protected $description = 'Read inbox metadata and report supplier eligibility without importing attachments';

    public function handle(GmailMailboxAccess $access, GmailImportIntake $intake, GmailMessageReader $reader): int
    {
        $counts = [];
        $token = null;
        do {
            $token = $access->run(function (Gmail $gmail) use (&$counts, $intake, $reader, $token): ?string {
                $page = $gmail->users_messages->listUsersMessages('me', array_filter(['labelIds' => ['INBOX'], 'maxResults' => 20, 'pageToken' => $token]));
                foreach ($page->getMessages() ?? [] as $item) {
                    $message = $reader->attachmentMetadata($gmail, $item->getId());
                    $headers = array_values(array_filter($message->getPayload()?->getHeaders() ?? [], fn ($header): bool => strcasecmp($header->getName(), 'From') === 0));
                    $sender = count($headers) === 1 ? $intake->sender($headers[0]->getValue()) : null;
                    $parts = [];
                    if ($message->getPayload()) {
                        $intake->parts($message->getPayload(), $parts);
                    }
                    $routing = $sender ? $intake->routing($sender, $parts) : null;
                    if (! $sender || ! in_array('INBOX', $message->getLabelIds() ?? [], true)) {
                        $reason = $sender ? 'not_in_inbox' : 'invalid_sender';
                        $counts[$reason] = ($counts[$reason] ?? 0) + 1;

                        continue;
                    }
                    foreach ($routing['manifest'] as $entry) {
                        $reason = $entry['reason'] ?? 'eligible';
                        $counts[$reason] = ($counts[$reason] ?? 0) + 1;
                    }
                    if (! $parts) {
                        $counts['no_candidates'] = ($counts['no_candidates'] ?? 0) + 1;
                    }
                }

                return $page->getNextPageToken();
            });
        } while ($token);
        $this->table(['Eligibility', 'Attachments'], collect($counts)->map(fn (int $count, string $reason): array => [$reason, $count])->values()->all());

        return self::SUCCESS;
    }
}
