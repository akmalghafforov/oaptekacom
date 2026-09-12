<?php

namespace App\Services;

use App\DataTransferObjects\IngestionContext;
use App\DataTransferObjects\StoredImportFile;
use App\Enums\PriceListImportSource;
use App\Enums\PriceListImportStatus;
use App\Jobs\PreparePriceListImport;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSenderAddress;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SupplierPriceListIngestor
{
    public function ingestFromEmail(StoredImportFile $file, string $senderEmail, ?string $messageId = null, ?CarbonImmutable $receivedAt = null): PriceListImport
    {
        $address = SupplierSenderAddress::query()->where('normalized_email', mb_strtolower(trim($senderEmail)))->firstOrFail();

        return $this->ingest($address->supplier, $file, new IngestionContext(PriceListImportSource::Email, senderEmail: $senderEmail, messageId: $messageId, receivedAt: $receivedAt));
    }

    public function ingest(Organization $supplier, StoredImportFile $file, IngestionContext $context): PriceListImport
    {
        $profile = $supplier->importProfile()->where('is_active', true)->first();
        if ($profile === null) {
            throw ValidationException::withMessages(['file' => 'Для поставщика не настроен активный профиль импорта.']);
        }
        $existing = PriceListImport::query()->whereBelongsTo($supplier, 'supplier')->where('sha256', $file->sha256)->first();
        if ($existing !== null) {
            if ($existing->file_path !== $file->path) {
                Storage::disk($file->disk)->delete($file->path);
            }

            return $existing;
        }

        return DB::transaction(function () use ($supplier, $file, $context, $profile): PriceListImport {
            $import = PriceListImport::create([
                'supplier_organization_id' => $supplier->id, 'supplier_import_profile_id' => $profile->id,
                'profile_snapshot' => $profile->configuration, 'source_type' => $context->source,
                'file_path' => $file->path, 'original_filename' => $file->originalFilename, 'mime_type' => $file->mimeType,
                'file_size' => $file->size, 'sha256' => $file->sha256, 'sender_email' => $context->senderEmail,
                'message_id' => $context->messageId, 'received_at' => $context->receivedAt, 'initiated_by' => $context->actor?->id,
                'status' => PriceListImportStatus::Pending, 'summary' => [],
            ]);
            PreparePriceListImport::dispatch($import)->onQueue(config('price-list-imports.queue'))->afterCommit();

            return $import;
        });
    }
}
