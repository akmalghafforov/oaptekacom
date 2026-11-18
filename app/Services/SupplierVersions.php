<?php

namespace App\Services;

use App\Enums\PriceListImportStatus;
use App\Models\ExternalDownload;
use App\Models\GmailImportReceipt;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierEmailDelivery;
use App\Models\SupplierVersionClaim;
use App\Services\PriceList\WorkbookReader;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class SupplierVersions
{
    public function enabled(): bool
    {
        return (bool) config('price-list-imports.daily_versions', false)
            || in_array($this->workflowStatus(), ['prepared', 'enabled'], true);
    }

    public function workflowStatus(): ?string
    {
        if (! Schema::hasTable('supplier_import_workflow_states')) {
            return null;
        }

        return DB::table('supplier_import_workflow_states')->where('workflow', 'daily_versions')->value('status');
    }

    public function paused(): bool
    {
        return Cache::get('supplier-imports:dispatch-paused', false) || in_array($this->workflowStatus(), ['preparing', 'prepared'], true);
    }

    public function day(CarbonInterface $date): string
    {
        return $date->copy()->setTimezone('Asia/Dushanbe')->toDateString();
    }

    /** @param array<string, mixed> $profile @return array{date: CarbonImmutable, precision: string, origin: string} */
    public function publication(string $filename, array $profile, CarbonInterface $arrival, ?CarbonInterface $explicit = null): array
    {
        if ($explicit) {
            return ['date' => CarbonImmutable::instance($explicit), 'precision' => 'day', 'origin' => 'supplied'];
        }
        $inventory = $profile['inventory'] ?? [];
        if (($inventory['source'] ?? null) === 'filename' && @preg_match($inventory['pattern'] ?? '', $filename, $matches) === 1) {
            $raw = $matches[$inventory['group'] ?? 1] ?? '';
            foreach ($inventory['formats'] ?? $profile['date_formats'] ?? ['d.m.Y', 'Y-m-d'] as $format) {
                try {
                    $date = CarbonImmutable::createFromFormat('!'.$format, $raw, 'Asia/Dushanbe');
                    if ($date && $date->format($format) === $raw) {
                        return ['date' => $date, 'precision' => str_contains($format, 's') ? 'second' : (str_contains($format, 'i') ? 'minute' : 'day'), 'origin' => 'filename'];
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        if (preg_match('/(?<!\d)(\d{2})[.\-_ ](\d{2})[.\-_ ](\d{4})(?!\d)/u', $filename, $parts)) {
            $raw = $parts[3].'-'.$parts[2].'-'.$parts[1];
        } elseif (preg_match('/(?<!\d)(\d{4})[.\-_ ](\d{2})[.\-_ ](\d{2})(?!\d)/u', $filename, $parts)) {
            $raw = $parts[1].'-'.$parts[2].'-'.$parts[3];
        } else {
            $raw = '';
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $raw, 'Asia/Dushanbe');
            if ($date && $date->format('Y-m-d') === $raw) {
                return ['date' => $date, 'precision' => 'day', 'origin' => 'filename'];
            }
        } catch (\Throwable) {
            // An undated file uses the agreed local arrival day.
        }

        return ['date' => CarbonImmutable::instance($arrival)->setTimezone('Asia/Dushanbe')->startOfDay(), 'precision' => 'day', 'origin' => 'arrival'];
    }

    /** @return array{date: ?CarbonImmutable, precision: ?string, origin: string} */
    public function workbookPublication(PriceListImport $import): array
    {
        $configuration = $import->profile_snapshot['inventory'] ?? [];
        if (($configuration['source'] ?? null) !== 'cell' || ! preg_match('/^([A-Z]{1,3})(\d+)$/', strtoupper($configuration['cell'] ?? ''), $matches)) {
            return ['date' => null, 'precision' => null, 'origin' => 'cell'];
        }
        $value = app(WorkbookReader::class)->rows($import, (int) $matches[2], (int) $matches[2])[(int) $matches[2]][$matches[1]] ?? null;
        if (is_numeric($value) && (float) $value > 0) {
            return ['date' => CarbonImmutable::instance(Date::excelToDateTimeObject((float) $value, 'Asia/Dushanbe')), 'precision' => floor((float) $value) === (float) $value ? 'day' : 'second', 'origin' => 'cell'];
        }
        if ($value instanceof \DateTimeInterface) {
            return ['date' => CarbonImmutable::instance($value)->setTimezone('Asia/Dushanbe'), 'precision' => 'second', 'origin' => 'cell'];
        }
        foreach ($configuration['formats'] ?? $import->profile_snapshot['date_formats'] ?? ['d.m.Y', 'Y-m-d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, trim((string) $value), 'Asia/Dushanbe');
                if ($date && $date->format($format) === trim((string) $value)) {
                    return ['date' => $date, 'precision' => str_contains($format, 's') ? 'second' : (str_contains($format, 'i') ? 'minute' : 'day'), 'origin' => 'cell'];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return ['date' => null, 'precision' => null, 'origin' => 'cell'];
    }

    /** @param array{date: CarbonImmutable, precision: string, origin: string} $publication */
    public function resolveWorkbookDay(PriceListImport $import, array $publication): void
    {
        DB::transaction(function () use ($import, $publication): void {
            Organization::whereKey($import->supplier_organization_id)->lockForUpdate()->firstOrFail();
            $oldDay = $this->day($import->source_published_at);
            $import->update(['source_published_at' => $publication['date'], 'source_date_precision' => $publication['precision'],
                'source_metadata' => array_replace($import->source_metadata ?? [], ['publication_origin' => $publication['origin']])]);
            if ($import->source_type->value !== 'email') {
                return;
            }
            SupplierEmailDelivery::where('supplier_organization_id', $import->supplier_organization_id)->where('gmail_message_id', $import->message_id)
                ->update(['published_day' => $this->day($publication['date']), 'date_origin' => $publication['origin'], 'date_precision' => $publication['precision']]);
            if ($oldDay !== $this->day($publication['date']) && ! SupplierEmailDelivery::where('supplier_organization_id', $import->supplier_organization_id)->where('published_day', $oldDay)->exists()) {
                $receiptIds = GmailImportReceipt::where('price_list_import_id', $import->id)->pluck('id');
                SupplierVersionClaim::where('supplier_organization_id', $import->supplier_organization_id)->where('published_day', $oldDay)
                    ->where('source', 'email')->whereNull('price_list_import_id')->whereIn('gmail_import_receipt_id', $receiptIds)->delete();
            }
        }, 3);
    }

    public function freshnessReason(Organization $supplier, CarbonInterface $date): ?string
    {
        $day = $this->day($date);
        if ($day > now('Asia/Dushanbe')->toDateString()) {
            return 'future_published_day';
        }
        $watermark = $supplier->successful_price_list_day;
        if (! $watermark) {
            $watermark = PriceListImport::where('supplier_organization_id', $supplier->id)
                ->whereIn('status', [PriceListImportStatus::Completed, PriceListImportStatus::Superseded])
                ->get()->map(fn (PriceListImport $import): string => $this->day($import->source_published_at ?? $import->inventory_at ?? $import->received_at ?? $import->created_at))->max();
        }

        return $watermark && $day <= (string) $watermark ? 'already_imported_day' : null;
    }

    /** Caller holds the organization lock. */
    public function claim(Organization $supplier, CarbonInterface $date, string $source, ?int $importId = null, ?int $receiptId = null): ?SupplierVersionClaim
    {
        if ($this->freshnessReason($supplier, $date)) {
            return null;
        }
        $claim = SupplierVersionClaim::firstOrCreate(['supplier_organization_id' => $supplier->id, 'published_day' => $this->day($date)], [
            'source' => $source, 'price_list_import_id' => $importId, 'gmail_import_receipt_id' => $receiptId,
        ]);
        if ($claim->price_list_import_id && $claim->source !== $source
            && PriceListImport::whereKey($claim->price_list_import_id)->where('status', PriceListImportStatus::Failed)->exists()
            && $claim->external_download_id) {
            $claim->update(['source' => $source, 'state' => 'reserved', 'external_download_id' => null, 'price_list_import_id' => null]);
        }
        if ($claim->state === 'failed' && $claim->source !== $source && $claim->external_download_id
            && ExternalDownload::whereKey($claim->external_download_id)->whereIn('status', ['failed', 'cancelled'])->exists()) {
            $claim->update(['source' => $source, 'state' => 'reserved', 'external_download_id' => null, 'price_list_import_id' => null]);
        }
        if ($claim->state === 'completed' || ($claim->source !== $source) || ($claim->price_list_import_id && $claim->price_list_import_id !== $importId)) {
            return null;
        }
        if ($importId) {
            $claim->update(['price_list_import_id' => $importId, 'state' => 'processing']);
        }
        if ($receiptId) {
            $claim->update(['gmail_import_receipt_id' => $claim->gmail_import_receipt_id ?? $receiptId]);
        }

        return $claim;
    }

    public function attach(PriceListImport $import): ?string
    {
        return DB::transaction(function () use ($import): ?string {
            $supplier = Organization::whereKey($import->supplier_organization_id)->lockForUpdate()->firstOrFail();
            if (! $import->source_published_at) {
                return 'missing_published_day';
            }
            if ($reason = $this->freshnessReason($supplier, $import->source_published_at)) {
                return $reason;
            }
            $claim = $this->claim($supplier, $import->source_published_at, $import->source_type->value, $import->id);

            return $claim ? null : 'version_owned';
        }, 3);
    }

    public function reason(PriceListImport $import, Organization $supplier): ?string
    {
        if (! $import->source_published_at) {
            return 'missing_published_day';
        }
        if ($reason = $this->freshnessReason($supplier, $import->source_published_at)) {
            return $reason;
        }
        $claim = SupplierVersionClaim::where('supplier_organization_id', $supplier->id)->where('published_day', $this->day($import->source_published_at))->first();

        return $claim?->price_list_import_id === $import->id && $claim->state !== 'completed' ? null : 'version_owned';
    }

    /** Caller holds the organization lock and the activation transaction. */
    public function complete(PriceListImport $import, Organization $supplier): void
    {
        $day = $this->day($import->source_published_at);
        SupplierVersionClaim::where('supplier_organization_id', $supplier->id)->where('published_day', $day)
            ->where('price_list_import_id', $import->id)->update(['state' => 'completed', 'completed_at' => now()]);
        $supplier->update(['successful_price_list_day' => $day]);
    }
}
