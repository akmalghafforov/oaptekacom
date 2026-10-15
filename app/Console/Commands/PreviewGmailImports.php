<?php

namespace App\Console\Commands;

use App\Enums\OrganizationType;
use App\Models\SupplierSenderAddress;
use App\Services\GmailImportIntake;
use App\Services\GmailMailboxAccess;
use Google\Service\Gmail;
use Illuminate\Console\Command;

class PreviewGmailImports extends Command
{
    protected $signature = 'gmail-imports:preview';

    protected $description = 'Read inbox metadata and report supplier eligibility without importing attachments';

    public function handle(GmailMailboxAccess $access, GmailImportIntake $intake): int
    {
        $counts = [];
        $token = null;
        do {
            $token = $access->run(function (Gmail $gmail) use (&$counts, $intake, $token): ?string {
                $page = $gmail->users_messages->listUsersMessages('me', array_filter(['labelIds' => ['INBOX'], 'maxResults' => 20, 'pageToken' => $token]));
                foreach ($page->getMessages() ?? [] as $item) {
                    $message = $gmail->users_messages->get('me', $item->getId(), ['format' => 'metadata', 'metadataHeaders' => ['From']]);
                    $headers = array_values(array_filter($message->getPayload()?->getHeaders() ?? [], fn ($header): bool => strcasecmp($header->getName(), 'From') === 0));
                    $sender = count($headers) === 1 ? $intake->sender($headers[0]->getValue()) : null;
                    $supplier = $sender ? SupplierSenderAddress::where('normalized_email', $sender)->first()?->supplier : null;
                    $reason = match (true) {
                        ! $sender => 'invalid_sender', ! $supplier => 'unknown_supplier',
                        $supplier->status !== 'active' || $supplier->type !== OrganizationType::Wholesaler => 'inactive_supplier',
                        ! $supplier->importProfile => 'missing_profile', ! $supplier->importProfile->is_active => 'inactive_profile', default => 'eligible',
                    };
                    $counts[$reason] = ($counts[$reason] ?? 0) + 1;
                }

                return $page->getNextPageToken();
            });
        } while ($token);
        $this->table(['Eligibility', 'Messages'], collect($counts)->map(fn (int $count, string $reason): array => [$reason, $count])->values()->all());

        return self::SUCCESS;
    }
}
