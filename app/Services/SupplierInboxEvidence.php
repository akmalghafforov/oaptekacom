<?php

namespace App\Services;

use App\Models\GmailImportReceipt;
use App\Models\GmailMessage;
use App\Models\Organization;
use App\Models\SupplierEmailDelivery;
use App\Models\SupplierVersionClaim;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SupplierInboxEvidence
{
    public function __construct(private SupplierAttachmentResolver $resolver, private SupplierVersions $versions) {}

    /** @param list<array<string, mixed>> $manifest */
    public function record(string $mailbox, string $messageId, string $sender, CarbonInterface $arrival, array $manifest): void
    {
        if (preg_match('/<([^<>]+)>/', $sender, $matches)) {
            $sender = $matches[1];
        }
        foreach ($manifest as $entry) {
            $filename = $entry['filename'] ?? $entry['name'] ?? '';
            $suppliers = $this->resolver->matchingSuppliers(mb_strtolower(trim($sender)), $filename);
            if ($suppliers->count() !== 1 || $this->resolver->eligibilityReason($suppliers->sole(), $filename)) {
                continue;
            }
            $supplier = $suppliers->sole();
            $publication = $this->versions->publication($filename, $supplier->importProfile->configuration, $arrival);
            SupplierEmailDelivery::firstOrCreate([
                'mailbox_email' => mb_strtolower($mailbox), 'gmail_message_id' => $messageId, 'supplier_organization_id' => $supplier->id,
            ], ['received_at' => $arrival, 'published_day' => $this->versions->day($publication['date']),
                'date_origin' => $publication['origin'], 'date_precision' => $publication['precision'], 'manifest' => $manifest]);
        }
    }

    public function reconcileStored(): void
    {
        GmailMessage::whereNotNull('attachment_manifest')->eachById(function (GmailMessage $message): void {
            $this->record($message->mailbox_email, $message->gmail_message_id, $message->sender, $message->received_at, $message->attachment_manifest);
        });
        GmailImportReceipt::whereNotNull('sender_email')->whereNotNull('received_at')->eachById(function (GmailImportReceipt $receipt): void {
            $manifest = $receipt->attachment_manifest ?? [];
            if ($receipt->selected_part && ! collect($manifest)->contains('filename', $receipt->selected_part['filename'] ?? '')) {
                $manifest[] = $receipt->selected_part;
            }
            $this->record($receipt->mailbox_email, $receipt->gmail_message_id, $receipt->sender_email, $receipt->received_at, $manifest);
        });
    }

    public function exists(int $supplierId, CarbonInterface $date): bool
    {
        return SupplierEmailDelivery::where('supplier_organization_id', $supplierId)->where('published_day', $this->versions->day($date))->exists();
    }

    public function reserve(Organization $supplier, CarbonInterface $date): void
    {
        DB::transaction(function () use ($supplier, $date): void {
            $supplier = Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            $this->versions->claim($supplier, $date, 'email');
        }, 3);
    }

    /** Returns false while a competing owner is active; completed versions are retained as skipped receipts. */
    public function ready(GmailImportReceipt $receipt, string $filename): bool
    {
        return DB::transaction(function () use ($receipt, $filename): bool {
            $supplier = Organization::whereKey($receipt->supplier_organization_id)->lockForUpdate()->firstOrFail();
            $publication = $this->versions->publication($filename, $supplier->importProfile->configuration, $receipt->received_at);
            $date = $publication['date'];
            $claim = SupplierVersionClaim::where('supplier_organization_id', $supplier->id)->where('published_day', $this->versions->day($date))->first();
            if ($reason = $this->versions->freshnessReason($supplier, $date)) {
                $receipt->update(['status' => 'skipped', 'reason' => $reason, 'price_list_import_id' => $claim?->price_list_import_id]);

                return false;
            }
            $owned = $this->versions->claim($supplier, $date, 'email', receiptId: $receipt->id);
            if (! $owned || ($owned->gmail_import_receipt_id && $owned->gmail_import_receipt_id !== $receipt->id)) {
                $receipt->update(['reason' => 'version_processing', 'next_attempt_at' => now()->addMinute()]);
                $receipt->decrement('attempts', min(1, $receipt->attempts));

                return false;
            }

            return true;
        }, 3);
    }
}
