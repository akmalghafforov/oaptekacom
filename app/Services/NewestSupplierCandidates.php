<?php

namespace App\Services;

use App\Enums\PriceListImportStatus;
use App\Models\ExternalAvailability;
use App\Models\ExternalDownload;
use App\Models\ExternalServiceState;
use App\Models\ExternalSupplierPartner;
use App\Models\GmailImportReceipt;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSynchronization;
use App\Services\PriceList\CatalogFingerprint;
use Carbon\CarbonInterface;

class NewestSupplierCandidates
{
    /** A null comparison means overlapping publication intervals cannot be ordered. */
    public function compare(CarbonInterface $date, string $precision, CarbonInterface $otherDate, string $otherPrecision): ?int
    {
        if ($precision === $otherPrecision && $date->equalTo($otherDate)) {
            return 0;
        }
        $end = $this->end($date, $precision);
        $otherEnd = $this->end($otherDate, $otherPrecision);
        if ($end->lte($otherDate)) {
            return -1;
        }
        if ($otherEnd->lte($date)) {
            return 1;
        }

        return null;
    }

    public function dateReason(?CarbonInterface $date, ?string $precision): ?string
    {
        if (! $date || ! in_array($precision, ['day', 'minute', 'second'], true)) {
            return 'review_missing_date';
        }
        if ($date->gt(now())) {
            return 'review_future_date';
        }

        return null;
    }

    private function end(CarbonInterface $date, string $precision): CarbonInterface
    {
        return match ($precision) {
            'day' => $date->copy()->addDay(), 'minute' => $date->copy()->addMinute(), default => $date->copy()->addSecond(),
        };
    }

    /** @return list<array<string, mixed>> */
    public function known(SupplierSynchronization $config): array
    {
        $entries = [];
        $policy = app(SupplierSourcePolicy::class);
        foreach (PriceListImport::where('supplier_organization_id', $config->supplier_organization_id)->whereIn('source_type', $config->enabledSources())
            ->whereIn('status', [PriceListImportStatus::Preview, PriceListImportStatus::Committing])->get() as $import) {
            if (($import->source_type->value === 'email' && $this->publicationReason($import) !== null) || $policy->validationReason($import) !== null || ($import->source_metadata['configuration_version'] ?? null) !== $config->version) {
                continue;
            }
            $entries[] = ['source' => $import->source_type->value, 'date' => $import->source_published_at, 'precision' => $import->source_date_precision, 'import' => $import];
        }
        foreach (ExternalSupplierPartner::where('supplier_organization_id', $config->supplier_organization_id)->whereIn('source', $config->enabledSources())->where('profile_active', true)->get() as $partner) {
            $observation = ExternalAvailability::where('external_supplier_partner_id', $partner->id)->orderByDesc('last_checked_at')->orderByDesc('id')->first();
            if (! $observation?->available || ! $partner->profile_configuration) {
                continue;
            }
            $download = ExternalDownload::where('supplier_organization_id', $config->supplier_organization_id)->where('source', $partner->source)->where('version_key', $observation->version_key)->first();
            if ($download && $download->snapshot['configuration_version'] === $config->version && in_array($download->status, ['completed', 'failed', 'cancelled', 'cooldown', 'retry', 'paused'], true)) {
                continue;
            }
            $entries[] = ['source' => $partner->source, 'date' => $observation->published_at, 'precision' => $observation->date_precision, 'availability' => $observation];
        }

        return $entries;
    }

    /** @param list<array<string, mixed>> $entries @return list<array<string, mixed>> */
    public function ordered(SupplierSynchronization $config, array $entries): array
    {
        foreach ($entries as $index => $entry) {
            $entries[$index]['review'] = $this->dateReason($entry['date'], $entry['precision']);
        }
        foreach ($entries as $index => $entry) {
            if ($entries[$index]['review']) {
                continue;
            }
            foreach ($entries as $otherIndex => $other) {
                if (! $config->reviewsConflicts() && $index !== $otherIndex && ! $this->dateReason($other['date'], $other['precision'])
                    && $this->compare($entry['date'], $entry['precision'], $other['date'], $other['precision']) === null) {
                    $entries[$index]['review'] = 'review_date_precision';
                }
            }
        }
        $priorities = array_flip($config->enabledSources());
        usort($entries, function (array $a, array $b) use ($priorities): int {
            if ((bool) $a['review'] !== (bool) $b['review']) {
                return $a['review'] ? 1 : -1;
            }

            return ($b['date']?->timestamp ?? 0) <=> ($a['date']?->timestamp ?? 0)
                ?: $priorities[$a['source']] <=> $priorities[$b['source']];
        });

        return $entries;
    }

    public function mailboxPending(int $supplierId, bool $recentOnly = false): bool
    {
        return GmailImportReceipt::where('supplier_organization_id', $supplierId)
            ->whereIn('status', ['pending', 'processing', 'retryable'])->whereNull('price_list_import_id')
            ->when($recentOnly, fn ($query) => $query->where('created_at', '>', now()->subMinutes(15)))->exists()
            || PriceListImport::where('supplier_organization_id', $supplierId)->where('source_type', 'email')
                ->whereIn('status', [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Committing])
                ->when($recentOnly, fn ($query) => $query->where('created_at', '>', now()->subMinutes(15)))->exists();
    }

    public function publicationReason(PriceListImport $import): ?string
    {
        if ($import->source_type->value === 'email' && ! isset($import->source_metadata['date_confirmed_by'])
            && ! in_array($import->source_metadata['publication_origin'] ?? null, ['cell', 'filename'], true)) {
            return 'review_missing_date';
        }

        return $this->dateReason($import->source_published_at, $import->source_date_precision);
    }

    public function reason(PriceListImport $import, SupplierSynchronization $config, ?Organization $supplier = null): ?string
    {
        if ($reason = $this->publicationReason($import)) {
            return $reason;
        }
        $sources = $config->enabledSources();
        if ($import->source_type->value !== 'email' && in_array('email', $sources, true) && $this->mailboxPending($config->supplier_organization_id, true)) {
            return 'mailbox_processing';
        }
        $current = ($supplier ?? $import->supplier)->activePriceListImport()->first();
        if ($current && $current->id !== $import->id) {
            if ($config->reviewsConflicts() && ($fingerprint = app(CatalogFingerprint::class)->calculate($import)) !== null
                && $fingerprint === app(CatalogFingerprint::class)->calculate($current)) {
                return 'unchanged_content';
            }
            if (! $config->reviewsConflicts() && ($import->source_fingerprint ?? $import->sha256) && ($import->source_fingerprint ?? $import->sha256) === ($current->source_fingerprint ?? $current->sha256)) {
                return 'unchanged_content';
            }
            if ($this->publicationReason($current)) {
                return 'review_active_date_unknown';
            }
            $order = $this->compare($import->source_published_at, $import->source_date_precision, $current->source_published_at, $current->source_date_precision);
            if ($order === null && ! $this->reviewedAgainst($import, $current, $config)) {
                return 'review_date_precision';
            }
            if ($order < 0) {
                return 'older_than_active';
            }
            if ($order === 0 && ! $this->reviewedAgainst($import, $current, $config)) {
                if ($config->reviewsConflicts()) {
                    if (! $this->preferredTie($import->source_type->value, $current->source_type->value, $config)) {
                        return 'review_equal_date_conflict';
                    }
                } elseif ($current->source_type === $import->source_type) {
                    return 'same_source_equal_date';
                }
                $priority = $config->reviewsConflicts() ? false : array_search($current->source_type->value, $sources, true);
                if ($priority !== false && $priority <= array_search($import->source_type->value, $sources, true)) {
                    return 'equal_date_lower_priority';
                }
            }
        }
        foreach ($this->ordered($config, $this->known($config)) as $candidate) {
            if (($candidate['import']->id ?? null) === $import->id || $candidate['review'] === 'review_missing_date' || $candidate['review'] === 'review_future_date') {
                continue;
            }
            if (isset($candidate['availability'])) {
                $state = ExternalServiceState::find($candidate['source']);
                if ($state?->status === 'paused' || $state?->cooldown_until?->isFuture()) {
                    continue;
                }
            }
            $order = $this->compare($import->source_published_at, $import->source_date_precision, $candidate['date'], $candidate['precision']);
            $other = $candidate['import'] ?? null;
            if ($other && ($order === null || $order === 0) && $this->reviewedAgainst($import, $other, $config)) {
                continue;
            }
            if ($order === null) {
                return 'review_date_precision';
            }
            if ($config->reviewsConflicts() && $order === 0) {
                if (! $other) {
                    return 'newer_candidate_pending';
                }
                $fingerprint = app(CatalogFingerprint::class)->calculate($import);
                if ($fingerprint && $fingerprint === app(CatalogFingerprint::class)->calculate($other)) {
                    continue;
                }
                if (! $this->preferredTie($import->source_type->value, $candidate['source'], $config)) {
                    return 'review_equal_date_conflict';
                }

                continue;
            }
            if ($order < 0 || ($order === 0 && array_search($candidate['source'], $sources, true) < array_search($import->source_type->value, $sources, true))) {
                return 'newer_candidate_pending';
            }
        }

        return null;
    }

    /** @return list<PriceListImport> */
    public function reviewComparisons(PriceListImport $import, SupplierSynchronization $config): array
    {
        $others = collect($this->known($config))->pluck('import')->filter()->push($import->supplier->activePriceListImport()->first())
            ->filter(fn ($other): bool => $other !== null && $other->id !== $import->id)->unique('id')->sortBy('id');

        return $others->values()->all();
    }

    public function comparisonToken(PriceListImport $import, SupplierSynchronization $config): string
    {
        return hash('sha256', json_encode([$this->reviewToken($import, $config), array_map(fn ($other): string => $this->reviewToken($other, $config), $this->reviewComparisons($import, $config))], JSON_THROW_ON_ERROR));
    }

    private function preferredTie(string $source, string $otherSource, SupplierSynchronization $config): bool
    {
        return $source !== $otherSource && ($config->workflow_policy['tie_source'] ?? null) === $source;
    }

    public function reviewToken(PriceListImport $import, SupplierSynchronization $config): string
    {
        return hash('sha256', json_encode([$import->id, $config->version, $import->source_published_at?->toIso8601String(), $import->source_date_precision,
            app(CatalogFingerprint::class)->calculate($import) ?? $import->source_fingerprint ?? $import->sha256], JSON_THROW_ON_ERROR));
    }

    private function reviewedAgainst(PriceListImport $import, PriceListImport $other, SupplierSynchronization $config): bool
    {
        $review = $import->source_metadata['conflict_review'] ?? [];

        return $config->reviewsConflicts() && ($review['candidate'] ?? null) === $this->reviewToken($import, $config)
            && in_array($this->reviewToken($other, $config), $review['others'] ?? [], true);
    }
}
