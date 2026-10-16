<?php

namespace App\Services;

use App\DataTransferObjects\IngestionContext;
use App\DataTransferObjects\StoredImportFile;
use App\Enums\OrganizationType;
use App\Enums\PriceListImportSource;
use App\Enums\PriceListImportStatus;
use App\Jobs\PreparePriceListImport;
use App\Models\GmailImportContentClaim;
use App\Models\GmailImportReceipt;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSenderAddress;
use App\Services\PriceList\ProductCategoryRuleSetResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SupplierPriceListIngestor
{
    public function __construct(private SupplierAttachmentResolver $resolver) {}

    /** @return array{outcome: string, import: ?PriceListImport} */
    public function ingestGmailReceipt(GmailImportReceipt $receipt, StoredImportFile $file): array
    {
        $result = DB::transaction(function () use ($receipt, $file): array {
            $receipt = GmailImportReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->price_list_import_id) {
                return ['outcome' => 'duplicate', 'import' => PriceListImport::findOrFail($receipt->price_list_import_id)];
            }
            $supplier = Organization::whereKey($receipt->supplier_organization_id)->lockForUpdate()->first();
            $address = SupplierSenderAddress::where('normalized_email', $receipt->sender_email)->where('supplier_organization_id', $receipt->supplier_organization_id)->lockForUpdate()->first();
            $profile = $supplier?->importProfile()->lockForUpdate()->first();
            $reason = match (true) {
                ! $supplier || $supplier->status !== 'active' || $supplier->type !== OrganizationType::Wholesaler => 'inactive_supplier',
                $address?->supplier_organization_id !== $supplier->id => 'sender_mapping_changed',
                ! $profile => 'missing_profile',
                ! $profile->is_active => 'inactive_profile',
                $profile->file_type->value !== ($receipt->selected_part['file_type'] ?? null) => 'profile_changed',
                $this->resolver->matchingSuppliers($receipt->sender_email, $file->originalFilename)->modelKeys() !== [$supplier->id] => 'supplier_resolution_changed',
                default => null,
            };
            if ($reason) {
                $receipt->update(['status' => 'skipped', 'reason' => $reason]);

                return ['outcome' => 'skipped', 'import' => null];
            }
            $claim = GmailImportContentClaim::where('supplier_organization_id', $supplier->id)->where('sha256', $file->sha256)->first();
            $existing = $claim ? PriceListImport::findOrFail($claim->price_list_import_id) : PriceListImport::where('supplier_organization_id', $supplier->id)->where('sha256', $file->sha256)->oldest('id')->first();
            $duplicateReason = 'duplicate_content';
            if (! $existing) {
                $existing = PriceListImport::where('supplier_organization_id', $supplier->id)->where('source_type', PriceListImportSource::Email)->where('message_id', $receipt->gmail_message_id)->first();
                $duplicateReason = 'duplicate_message';
            }
            $import = $existing ?? $this->ingest($supplier, $file, new IngestionContext(PriceListImportSource::Email, senderEmail: $receipt->sender_email, messageId: $receipt->gmail_message_id, receivedAt: $receipt->received_at));
            if ($import->sha256 === $file->sha256) {
                GmailImportContentClaim::firstOrCreate(['supplier_organization_id' => $supplier->id, 'sha256' => $file->sha256], ['price_list_import_id' => $import->id]);
            }
            $receipt->update(['supplier_import_profile_id' => $profile->id, 'price_list_import_id' => $import->id, 'status' => $existing ? 'duplicate' : 'accepted', 'reason' => $existing ? $duplicateReason : null]);

            return ['outcome' => $existing ? 'duplicate' : 'accepted', 'import' => $import];
        }, 3);
        if ($result['import']?->file_path !== $file->path) {
            Storage::disk($file->disk)->delete($file->path);
        }

        return $result;
    }

    public function ingestFromEmail(StoredImportFile $file, string $senderEmail, ?string $messageId = null, ?CarbonImmutable $receivedAt = null): PriceListImport
    {
        $suppliers = $this->resolver->matchingSuppliers($senderEmail, $file->originalFilename);
        if ($suppliers->count() !== 1 || $this->resolver->eligibilityReason($suppliers->sole(), $file->originalFilename)) {
            throw ValidationException::withMessages(['file' => 'Вложение не соответствует единственному подходящему поставщику.']);
        }

        return $this->ingest($suppliers->sole(), $file, new IngestionContext(PriceListImportSource::Email, senderEmail: $senderEmail, messageId: $messageId, receivedAt: $receivedAt));
    }

    public function ingest(Organization $supplier, StoredImportFile $file, IngestionContext $context): PriceListImport
    {
        return DB::transaction(function () use ($supplier, $file, $context): PriceListImport {
            $supplier = Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();

            return $this->ingestLocked($supplier, $file, $context);
        }, 3);
    }

    private function ingestLocked(Organization $supplier, StoredImportFile $file, IngestionContext $context): PriceListImport
    {
        $profile = $supplier->importProfile()->where('is_active', true)->lockForUpdate()->first();
        if ($profile === null) {
            throw ValidationException::withMessages(['file' => 'Для поставщика не настроен активный профиль импорта.']);
        }
        $inFlight = PriceListImport::query()->whereBelongsTo($supplier, 'supplier')->where('sha256', $file->sha256)->whereIn('status', [PriceListImportStatus::Pending, PriceListImportStatus::Processing])->first();
        if ($inFlight !== null) {
            if ($inFlight->file_path !== $file->path) {
                Storage::disk($file->disk)->delete($file->path);
            }

            return $inFlight;
        }

        return DB::transaction(function () use ($supplier, $file, $context, $profile): PriceListImport {
            $ruleSet = app(ProductCategoryRuleSetResolver::class)->current();
            $duplicate = PriceListImport::query()
                ->whereBelongsTo($supplier, 'supplier')
                ->where('sha256', $file->sha256)
                ->whereIn('status', [PriceListImportStatus::Completed, PriceListImportStatus::Superseded])
                ->latest('id')
                ->first();
            $import = PriceListImport::create([
                'supplier_organization_id' => $supplier->id, 'supplier_import_profile_id' => $profile->id,
                'profile_snapshot' => array_replace($profile->configuration, ['file_type' => strtolower(pathinfo($file->originalFilename, PATHINFO_EXTENSION))]), 'source_type' => $context->source,
                'file_path' => $file->path, 'original_filename' => $file->originalFilename, 'mime_type' => $file->mimeType,
                'file_size' => $file->size, 'sha256' => $file->sha256, 'sender_email' => $context->senderEmail,
                'message_id' => $context->messageId, 'received_at' => $context->receivedAt, 'initiated_by' => $context->actor?->id,
                'product_category_rule_set_id' => $ruleSet->id, 'product_category_rule_set_checksum' => $ruleSet->checksum,
                'duplicate_of_import_id' => $duplicate?->id,
                'status' => PriceListImportStatus::Pending,
                'summary' => [],
            ]);
            PreparePriceListImport::dispatch($import)->onQueue(config('price-list-imports.queue'))->afterCommit();

            return $import;
        });
    }
}
