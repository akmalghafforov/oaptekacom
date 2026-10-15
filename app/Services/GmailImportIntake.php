<?php

namespace App\Services;

use App\DataTransferObjects\StoredImportFile;
use App\Enums\OrganizationType;
use App\Models\GmailConnection;
use App\Models\GmailImportReceipt;
use App\Models\SupplierSenderAddress;
use Carbon\CarbonImmutable;
use Google\Service\Exception as GoogleException;
use Google\Service\Gmail;
use Google\Service\Gmail\MessagePart;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GmailImportIntake
{
    public function __construct(private GmailMailboxAccess $access, private ImportFileValidator $validator, private SupplierPriceListIngestor $ingestor) {}

    public function process(GmailImportReceipt $receipt): void
    {
        if ($receipt->status !== 'pending') {
            return;
        }
        $this->access->run(function (Gmail $gmail, GmailConnection $connection) use ($receipt): void {
            if (strcasecmp($receipt->mailbox_email, $connection->email) !== 0) {
                $this->skip($receipt, 'mailbox_changed');

                return;
            }
            $message = $gmail->users_messages->get('me', $receipt->gmail_message_id, ['format' => 'full']);
            if (! in_array('INBOX', $message->getLabelIds() ?? [], true)) {
                $this->skip($receipt, 'not_in_inbox');

                return;
            }
            $headers = array_values(array_filter($message->getPayload()?->getHeaders() ?? [], fn ($header): bool => strcasecmp($header->getName(), 'From') === 0));
            $sender = count($headers) === 1 ? $this->sender($headers[0]->getValue()) : null;
            if (! $sender) {
                $this->skip($receipt, 'invalid_sender');

                return;
            }
            $receipt->update(['sender_email' => $sender, 'received_at' => CarbonImmutable::createFromTimestampMs((int) $message->getInternalDate())]);
            $supplier = SupplierSenderAddress::where('normalized_email', $sender)->first()?->supplier;
            if (! $supplier) {
                $this->skip($receipt, 'unknown_supplier');

                return;
            }
            $receipt->update(['supplier_organization_id' => $supplier->id]);
            if ($supplier->status !== 'active' || $supplier->type !== OrganizationType::Wholesaler) {
                $this->skip($receipt, 'inactive_supplier');

                return;
            }
            $profile = $supplier->importProfile;
            if (! $profile) {
                $this->skip($receipt, 'missing_profile');

                return;
            }
            $receipt->update(['supplier_import_profile_id' => $profile->id]);
            if (! $profile->is_active) {
                $this->skip($receipt, 'inactive_profile');

                return;
            }
            $parts = [];
            if ($message->getPayload()) {
                $this->parts($message->getPayload(), $parts);
            }
            $manifest = [];
            $candidates = [];
            foreach ($parts as $part) {
                $matches = strtolower(pathinfo($part->getFilename(), PATHINFO_EXTENSION)) === $profile->file_type->value;
                $manifest[] = ['part_id' => $part->getPartId(), 'attachment_id' => $part->getBody()?->getAttachmentId(), 'filename' => $part->getFilename(), 'size' => $part->getBody()?->getSize(), 'reason' => $matches ? null : 'format_mismatch'];
                if ($matches) {
                    $candidates[] = $part;
                }
            }
            $receipt->update(['attachment_manifest' => $manifest]);
            if (count($candidates) !== 1) {
                $this->skip($receipt, count($candidates) ? 'multiple_candidates' : 'no_candidates');

                return;
            }
            $part = $candidates[0];
            $receipt->update(['selected_part' => ['part_id' => $part->getPartId(), 'filename' => $part->getFilename(), 'file_type' => $profile->file_type->value]]);
            if (($part->getBody()?->getSize() ?? 0) > config('price-list-imports.max_file_kilobytes') * 1024) {
                $this->skip($receipt, 'invalid_attachment');

                return;
            }
            $body = $part->getBody();
            try {
                $data = $body?->getAttachmentId() ? $gmail->users_messages_attachments->get('me', $receipt->gmail_message_id, $body->getAttachmentId())->getData() : $body?->getData();
            } catch (GoogleException $exception) {
                if ($exception->getCode() !== 404) {
                    throw $exception;
                }
                $this->skip($receipt, 'attachment_unavailable');

                return;
            }
            $bytes = $this->decode($data ?? '');
            if ($bytes === null || ($body?->getSize() !== null && strlen($bytes) !== (int) $body->getSize())) {
                $this->skip($receipt, 'invalid_attachment');

                return;
            }
            $disk = config('price-list-imports.disk');
            $path = 'price-list-imports/'.$supplier->id.'/'.Str::uuid().'.'.$profile->file_type->value;
            if (! Storage::disk($disk)->put($path, $bytes)) {
                throw new \RuntimeException('private_storage_unavailable');
            }
            try {
                $this->validator->validate(Storage::disk($disk)->path($path), $part->getFilename(), $profile->file_type, $profile->configuration);
            } catch (ValidationException) {
                Storage::disk($disk)->delete($path);
                $this->skip($receipt, 'invalid_attachment');

                return;
            }
            $file = new StoredImportFile($disk, $path, $part->getFilename(), (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), strlen($bytes), hash('sha256', $bytes));
            $this->ingestor->ingestGmailReceipt($receipt, $file);
        });
    }

    public function sender(string $header): ?string
    {
        if (preg_match('/[\r\n]/', $header)) {
            return null;
        }
        $header = trim($header);
        if (str_contains($header, '<')) {
            if (! preg_match('/^(?:"(?:[^"\\\\]|\\\\.)*"|[^<>";,@:]*)\s*<([^<>]+)>$/D', $header, $matches)) {
                return null;
            }
            $header = $matches[1];
        }
        $header = trim($header);

        return filter_var($header, FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim($header)) : null;
    }

    public function decode(string $data): ?string
    {
        $limit = config('price-list-imports.max_file_kilobytes') * 1024;
        if (strlen($data) > (int) ceil($limit * 4 / 3) + 4 || ! preg_match('/^[A-Za-z0-9_-]*={0,2}$/D', $data) || strlen(rtrim($data, '=')) % 4 === 1) {
            return null;
        }
        if (str_contains($data, '=') && strlen($data) % 4 !== 0) {
            return null;
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) > $limit || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== rtrim($data, '=')) {
            return null;
        }

        return $decoded;
    }

    private function parts(MessagePart $part, array &$parts): void
    {
        if ($part->getFilename()) {
            $parts[] = $part;
        }
        foreach ($part->getParts() ?? [] as $child) {
            $this->parts($child, $parts);
        }
    }

    private function skip(GmailImportReceipt $receipt, string $reason): void
    {
        $receipt->update(['status' => 'skipped', 'reason' => $reason]);
    }
}
