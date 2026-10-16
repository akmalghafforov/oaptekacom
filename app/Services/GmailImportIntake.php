<?php

namespace App\Services;

use App\DataTransferObjects\StoredImportFile;
use App\Jobs\ProcessGmailImportReceipt;
use App\Models\GmailConnection;
use App\Models\GmailImportReceipt;
use Carbon\CarbonImmutable;
use Google\Service\Exception as GoogleException;
use Google\Service\Gmail;
use Google\Service\Gmail\MessagePart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class GmailImportIntake
{
    public function __construct(private GmailMailboxAccess $access, private ImportFileValidator $validator, private SupplierPriceListIngestor $ingestor, private SupplierAttachmentResolver $resolver, private GmailMessageReader $reader) {}

    public function process(GmailImportReceipt $receipt): void
    {
        if ($receipt->status !== 'pending') {
            return;
        }
        $childIds = $this->access->run(function (Gmail $gmail, GmailConnection $connection) use ($receipt): array {
            if (strcasecmp($receipt->mailbox_email, $connection->email) !== 0) {
                $this->skip($receipt, 'mailbox_changed');

                return [];
            }
            $message = $receipt->receipt_scope === 'message'
                ? $this->reader->attachmentMetadata($gmail, $receipt->gmail_message_id)
                : $gmail->users_messages->get('me', $receipt->gmail_message_id, ['format' => 'full']);
            if (! in_array('INBOX', $message->getLabelIds() ?? [], true)) {
                $this->skip($receipt, 'not_in_inbox');

                return [];
            }
            $headers = array_values(array_filter($message->getPayload()?->getHeaders() ?? [], fn ($header): bool => strcasecmp($header->getName(), 'From') === 0));
            $sender = count($headers) === 1 ? $this->sender($headers[0]->getValue()) : null;
            if (! $sender) {
                $this->skip($receipt, 'invalid_sender');

                return [];
            }
            $receipt->update(['sender_email' => $sender, 'received_at' => CarbonImmutable::createFromTimestampMs((int) $message->getInternalDate())]);
            $parts = [];
            if ($message->getPayload()) {
                $this->parts($message->getPayload(), $parts);
            }
            $routing = $this->routing($sender, $parts);
            if ($receipt->receipt_scope === 'message') {
                foreach ($routing['manifest'] as $entry) {
                    if ($entry['reason'] === 'ambiguous_supplier') {
                        Log::error('Ambiguous Gmail attachment supplier', ['message_id' => $receipt->gmail_message_id, 'sender' => $sender, 'filename' => $entry['filename'], 'supplier_ids' => $entry['supplier_ids']]);
                    }
                }

                return $this->route($receipt, $routing);
            }
            $group = $routing['groups'][$receipt->supplier_organization_id] ?? null;
            if (! $group) {
                $this->skip($receipt, 'supplier_resolution_changed');

                return [];
            }
            $supplier = $group['supplier'];
            $profile = $supplier->importProfile;
            $candidates = $group['candidates'];
            $receipt->update(['attachment_manifest' => $routing['manifest'], 'supplier_import_profile_id' => $profile?->id]);
            if (count($candidates) !== 1) {
                $this->skip($receipt, count($candidates) > 1 ? 'multiple_candidates' : $group['reason']);

                return [];
            }
            $part = $candidates[0];
            $receipt->update(['selected_part' => ['part_id' => $part->getPartId(), 'filename' => $part->getFilename(), 'file_type' => $profile->file_type->value]]);
            if (($part->getBody()?->getSize() ?? 0) > config('price-list-imports.max_file_kilobytes') * 1024) {
                $this->skip($receipt, 'invalid_attachment');

                return [];
            }
            $body = $part->getBody();
            try {
                $data = $body?->getAttachmentId() ? $gmail->users_messages_attachments->get('me', $receipt->gmail_message_id, $body->getAttachmentId())->getData() : $body?->getData();
            } catch (GoogleException $exception) {
                if ($exception->getCode() !== 404) {
                    throw $exception;
                }
                $this->skip($receipt, 'attachment_unavailable');

                return [];
            }
            $bytes = $this->decode($data ?? '');
            if ($bytes === null || ($body?->getSize() !== null && strlen($bytes) !== (int) $body->getSize())) {
                $this->skip($receipt, 'invalid_attachment');

                return [];
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

                return [];
            }
            $file = new StoredImportFile($disk, $path, $part->getFilename(), (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), strlen($bytes), hash('sha256', $bytes));
            $this->ingestor->ingestGmailReceipt($receipt, $file);

            return [];
        });
        /** Committed pending children are recovered by reconciliation if dispatch fails. */
        foreach ($childIds as $id) {
            try {
                ProcessGmailImportReceipt::dispatch($id)->afterCommit();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @param  list<MessagePart>  $parts
     * @return array{manifest: list<array>, groups: array<int, array>}
     */
    public function routing(string $sender, array $parts): array
    {
        $manifest = [];
        $groups = [];
        foreach ($parts as $part) {
            $suppliers = $this->resolver->matchingSuppliers($sender, $part->getFilename());
            $reason = match ($suppliers->count()) {
                0 => 'unknown_supplier',
                1 => $this->resolver->eligibilityReason($suppliers->sole(), $part->getFilename()),
                default => 'ambiguous_supplier',
            };
            $manifest[] = ['part_id' => $part->getPartId(), 'attachment_id' => $part->getBody()?->getAttachmentId(), 'filename' => $part->getFilename(), 'size' => $part->getBody()?->getSize(), 'supplier_ids' => $suppliers->modelKeys(), 'reason' => $reason];
            if ($suppliers->count() !== 1) {
                continue;
            }
            $supplier = $suppliers->sole();
            $groups[$supplier->id] ??= ['supplier' => $supplier, 'candidates' => [], 'reason' => $reason === 'format_mismatch' ? 'no_candidates' : $reason];
            if ($reason === null) {
                $groups[$supplier->id]['candidates'][] = $part;
            }
        }

        foreach ($manifest as &$entry) {
            if ($entry['reason'] === null && count($groups[$entry['supplier_ids'][0]]['candidates']) > 1) {
                $entry['reason'] = 'multiple_candidates';
            }
        }

        return compact('manifest', 'groups');
    }

    /**
     * @param  array{manifest: list<array>, groups: array<int, array>}  $routing
     * @return list<int>
     */
    private function route(GmailImportReceipt $receipt, array $routing): array
    {
        return DB::transaction(function () use ($receipt, $routing): array {
            $parent = GmailImportReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($parent->status !== 'pending') {
                return [];
            }
            $ids = [];
            $seen = [];
            foreach ($routing['groups'] as $supplierId => $group) {
                $seen[] = 'supplier:'.$supplierId;
                $child = GmailImportReceipt::firstOrCreate([
                    'mailbox_email' => $parent->mailbox_email, 'gmail_message_id' => $parent->gmail_message_id, 'receipt_scope' => 'supplier:'.$supplierId,
                ], ['parent_receipt_id' => $parent->id, 'supplier_organization_id' => $supplierId]);
                if ($child->price_list_import_id || in_array($child->status, ['accepted', 'duplicate', 'completed'], true)) {
                    continue;
                }
                $count = count($group['candidates']);
                $child->update([
                    'sender_email' => $parent->sender_email, 'received_at' => $parent->received_at,
                    'supplier_import_profile_id' => $group['supplier']->importProfile?->id,
                    'attachment_manifest' => $routing['manifest'], 'selected_part' => null,
                    'status' => $count === 1 ? 'pending' : 'skipped',
                    'reason' => $count === 1 ? null : ($count > 1 ? 'multiple_candidates' : $group['reason']),
                ]);
                if ($child->status === 'pending') {
                    $ids[] = $child->id;
                }
            }
            $parent->children()->whereNotIn('receipt_scope', $seen)->whereNull('price_list_import_id')->whereIn('status', ['pending', 'failed', 'skipped'])->update(['status' => 'skipped', 'reason' => 'supplier_resolution_changed']);
            $parent->update(['attachment_manifest' => $routing['manifest'], 'status' => 'routed', 'reason' => null]);

            return $ids;
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

    public function parts(MessagePart $part, array &$parts): void
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
