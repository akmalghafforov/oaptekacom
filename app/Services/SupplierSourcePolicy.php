<?php

namespace App\Services;

use App\Enums\PriceListImportStatus;
use App\Models\ExternalSupplierPartner;
use App\Models\GmailImportReceipt;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSyncAttempt;
use App\Models\SupplierSynchronization;
use Carbon\CarbonInterface;

class SupplierSourcePolicy
{
    public function configuration(PriceListImport $import): ?SupplierSynchronization
    {
        $configuration = SupplierSynchronization::where('supplier_organization_id', $import->supplier_organization_id)->first();

        return $import->source_type->value === 'email' && ! $configuration?->settings_confirmed ? null : $configuration;
    }

    public function validationReason(PriceListImport $import): ?string
    {
        $thresholds = $import->profile_snapshot['automatic'] ?? [];
        if ($import->valid_rows < max(1, (int) ($thresholds['minimum_valid_rows'] ?? 1))
            || $import->error_rows > (int) ($thresholds['maximum_error_rows'] ?? 0)
            || ($import->total_rows > 0 && $import->error_rows * 100 / $import->total_rows > (float) ($thresholds['maximum_error_percentage'] ?? 0))) {
            return 'validation_failed';
        }
        $fatal = $thresholds['fatal_warning_codes'] ?? [];
        if ($fatal !== [] && $import->rows()->whereNotNull('warnings')->get(['warnings'])->contains(function ($row) use ($fatal): bool {
            foreach ($row->warnings as $warning) {
                if (in_array(is_array($warning) ? ($warning['code'] ?? null) : $warning, $fatal, true)) {
                    return true;
                }
            }

            return false;
        })) {
            return 'validation_failed';
        }

        return null;
    }

    public function reason(PriceListImport $import, ?Organization $supplier = null): ?string
    {
        $config = $this->configuration($import);
        if (! $config && in_array($import->source_type->value, ['icorp', 'pricelist_tj'], true)) {
            return 'configuration_missing';
        }
        if (! $config || $import->source_type->value === 'manual') {
            return null;
        }
        $supplier ??= $import->supplier;
        $metadata = $import->source_metadata ?? [];
        $source = $import->source_type->value;
        $sources = $config->enabledSources();
        if ($supplier->status !== 'active') {
            return 'inactive_supplier';
        }
        if (! in_array($source, $sources, true)) {
            return 'source_disabled';
        }
        if (($metadata['trigger'] ?? 'automatic') !== 'manual' && ($config->paused || ! $config->automatic_enabled)) {
            return 'paused';
        }
        if (($metadata['configuration_version'] ?? null) !== $config->version) {
            return 'configuration_changed';
        }
        if ($source === 'email') {
            $profile = $supplier->importProfile()->first();
            if (! $profile?->is_active || ($metadata['profile_hash'] ?? '') !== self::profileHash($profile->configuration)) {
                return 'profile_changed';
            }
        } else {
            $partner = ExternalSupplierPartner::find($metadata['partner_id'] ?? null);
            if (! $partner || $partner->supplier_organization_id !== $supplier->id || $partner->source !== $source) {
                return 'mapping_changed';
            }
            if (! $partner->profile_active || $partner->profile_version !== ($metadata['profile_version'] ?? null)) {
                return 'profile_changed';
            }
        }
        if ($reason = $this->validationReason($import)) {
            return $reason;
        }
        $date = $import->source_published_at;
        if (! $date || ! $import->source_date_precision) {
            return 'review_missing_date';
        }
        if ($date->gt(now()->addMinutes(5))) {
            return 'review_future_date';
        }
        if ($date->lt(now()->subHours($config->freshness_hours))) {
            if ($this->latestPossibleDate($import)->gt(now()->subHours($config->freshness_hours))) {
                return 'review_date_precision';
            }

            return 'stale';
        }
        $preferred = array_slice($sources, 0, array_search($source, $sources, true));
        if ($this->unresolved($supplier->id, $preferred, $import->id)) {
            return 'preferred_unresolved';
        }
        foreach ($preferred as $preferredSource) {
            if ($this->freshValidated($supplier->id, $preferredSource, $config->freshness_hours, $import->id)) {
                return 'preferred_fresh';
            }
        }
        $current = $supplier->activePriceListImport()->first();
        if ($current && $current->id !== $import->id) {
            $currentDate = $current->source_published_at ?? $current->inventory_at;
            if (! $currentDate) {
                return 'review_active_date_unknown';
            }
            if ($date->lt($currentDate)) {
                return $this->latestPossibleDate($import)->gt($currentDate) ? 'review_date_precision' : 'older_than_active';
            }
            if ($date->equalTo($currentDate)) {
                if ($source !== $current->source_type->value) {
                    $currentPriority = array_search($current->source_type->value, $sources, true);
                    if ($currentPriority !== false && $currentPriority < array_search($source, $sources, true)) {
                        return 'equal_date_lower_priority';
                    }
                }
            } elseif ($current->source_date_precision === 'day' && $date->lt($currentDate->copy()->addDay())) {
                return 'review_date_precision';
            } elseif ($current->source_date_precision === 'minute' && $date->lt($currentDate->copy()->addMinute())) {
                return 'review_date_precision';
            }
        }

        return null;
    }

    public function freshValidated(int $supplierId, string $source, int $hours, ?int $except = null): bool
    {
        return PriceListImport::where('supplier_organization_id', $supplierId)->where('source_type', $source)
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->whereIn('status', [PriceListImportStatus::Preview, PriceListImportStatus::Committing, PriceListImportStatus::Completed, PriceListImportStatus::Superseded])
            ->get()->contains(function (PriceListImport $import) use ($hours): bool {
                $date = $import->source_published_at ?? ($import->source_type->value === 'email' ? $import->inventory_at : null);

                return $date !== null && $date->gte(now()->subHours($hours)) && $date->lte(now()->addMinutes(5))
                    && $this->validationReason($import) === null;
            });
    }

    /** @param list<string> $sources */
    public function unresolved(int $supplierId, array $sources, ?int $except = null): bool
    {
        if ($sources === []) {
            return false;
        }
        if (SupplierSyncAttempt::whereIn('source', $sources)->whereIn('status', ['fetching', 'processing', 'validated'])
            ->whereHas('run', fn ($query) => $query->where('active_supplier_id', $supplierId))->exists()) {
            return true;
        }
        if (PriceListImport::where('supplier_organization_id', $supplierId)->whereIn('source_type', $sources)
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->whereIn('status', [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Committing])->exists()) {
            return true;
        }

        if (PriceListImport::where('supplier_organization_id', $supplierId)->whereIn('source_type', $sources)
            ->when($except, fn ($query) => $query->whereKeyNot($except))->where('status', PriceListImportStatus::Preview)
            ->get()->contains(fn (PriceListImport $candidate): bool => $this->validationReason($candidate) === null && $candidate->source_published_at === null)) {
            return true;
        }

        return in_array('email', $sources, true) && GmailImportReceipt::where('supplier_organization_id', $supplierId)
            ->whereIn('status', ['pending', 'processing', 'retryable'])->whereNull('price_list_import_id')->exists();
    }

    private function latestPossibleDate(PriceListImport $import): CarbonInterface
    {
        return match ($import->source_date_precision) {
            'day' => $import->source_published_at->copy()->addDay(),
            'minute' => $import->source_published_at->copy()->addMinute(),
            default => $import->source_published_at,
        };
    }

    /** @param array<string, mixed> $profile */
    public static function profileHash(array $profile): string
    {
        return hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR));
    }
}
