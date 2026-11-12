<?php

namespace App\Services;

use App\DataTransferObjects\IngestionContext;
use App\Enums\PriceListImportSource;
use App\Enums\PriceListImportStatus;
use App\Jobs\DownloadExternalPricePage;
use App\Jobs\SynchronizeSupplier;
use App\Models\ExternalAvailability;
use App\Models\ExternalDownload;
use App\Models\ExternalServiceState;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSyncAttempt;
use App\Models\SupplierSynchronization;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ExternalPriceDownload
{
    public function __construct(private ExternalDownloadLease $lease, private ExternalServiceControl $control) {}

    public function start(SupplierSyncAttempt $attempt, SupplierSynchronization $config, ExternalSupplierPartner $partner, ?ExternalAvailability $observation = null): void
    {
        $evidence = $observation?->evidence ?? $partner->metadata ?? [];
        $version = $observation?->version_key ?? hash('sha256', json_encode([$partner->external_id, $evidence, $config->version, $attempt->supplier_sync_run_id], JSON_THROW_ON_ERROR));
        $download = DB::transaction(function () use ($attempt, $config, $partner, $observation, $evidence, $version): ExternalDownload {
            Organization::whereKey($config->supplier_organization_id)->lockForUpdate()->firstOrFail();
            $download = ExternalDownload::firstOrNew(['supplier_organization_id' => $config->supplier_organization_id, 'source' => $partner->source, 'version_key' => $version]);
            if ($download->exists) {
                $original = SupplierSyncAttempt::findOrFail($download->supplier_sync_attempt_id);
                if ($download->snapshot['configuration_version'] === $config->version) {
                    $attempt->update(['status' => $download->status === 'completed' ? ($original->price_list_import_id ? 'processing' : 'skipped') : ($download->status === 'failed' ? 'failed' : 'downloading'),
                        'price_list_import_id' => $original->price_list_import_id, 'reason' => $download->reason ?? 'version_claimed']);

                    return $download;
                }
                if ($download->worker_token && $download->worker_expires_at?->isFuture()) {
                    throw new ExternalServiceUnavailable('download_busy');
                }
                DB::table('external_download_pages')->where('external_download_id', $download->id)->delete();
            }
            $download->fill(['supplier_sync_attempt_id' => $attempt->id, 'external_availability_id' => $observation?->id, 'external_id' => $partner->external_id,
                'snapshot' => ['configuration_version' => $config->version, 'partner_id' => $partner->id, 'profile_version' => $partner->profile_version, 'profile' => $partner->profile_configuration, 'evidence' => $evidence],
                'status' => 'pending', 'next_page' => 1, 'received_rows' => 0, 'total_rows' => null, 'total_pages' => null, 'company' => null,
                'worker_token' => null, 'worker_expires_at' => null, 'retry_count' => 0, 'next_retry_at' => null, 'reason' => null])->save();
            $attempt->update(['status' => 'downloading', 'reason' => null]);

            return $download;
        }, 3);
        if (in_array($download->status, ['pending', 'downloading', 'retry'], true)) {
            DownloadExternalPricePage::dispatch($download->id)->onQueue(config('price-list-imports.queue'))->afterCommit();
        }
    }

    private function configured(ExternalDownload $download): bool
    {
        $config = SupplierSynchronization::where('supplier_organization_id', $download->supplier_organization_id)->first();
        $partner = ExternalSupplierPartner::find($download->snapshot['partner_id']);
        $attempt = SupplierSyncAttempt::with('run')->findOrFail($download->supplier_sync_attempt_id);

        return $config && $config->version === $download->snapshot['configuration_version'] && in_array($download->source, $config->enabledSources(), true)
            && ($attempt->run->trigger === 'manual' || (! $config->paused && $config->automatic_enabled))
            && $partner && $partner->supplier_organization_id === $download->supplier_organization_id && $partner->external_id === $download->external_id
            && $partner->profile_active && $partner->profile_version === $download->snapshot['profile_version'];
    }

    public function process(int $id): void
    {
        $worker = (string) Str::uuid();
        $download = DB::transaction(function () use ($id, $worker): ?ExternalDownload {
            $download = ExternalDownload::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($download->status, ['pending', 'downloading', 'retry'], true) || $download->next_retry_at?->isFuture()
                || ($download->worker_token && $download->worker_expires_at?->isFuture())) {
                return null;
            }
            $download->update(['worker_token' => $worker, 'worker_expires_at' => now()->addSeconds(140)]);

            return $download;
        }, 3);
        if (! $download) {
            return;
        }
        $owner = 'download:'.$id;
        $fence = null;
        $continue = false;
        $done = false;
        try {
            $fence = $this->lease->claim($owner);
            if (! $this->configured($download)) {
                throw new ExternalServiceUnavailable('configuration_changed');
            }
            $this->control->assertAvailable($download->source);
            SupplierSyncAttempt::whereKey($download->supplier_sync_attempt_id)->update(['status' => 'downloading', 'next_retry_at' => null]);
            if ($download->total_pages !== null && $download->next_page === $download->total_pages + 1) {
                $this->complete($download, $worker, $owner, $fence);

                return;
            }
            $page = Cache::lock('external-prices:'.$download->source, 140)->block(1, function () use ($download): array {
                $client = app($download->source === 'icorp' ? IcorpClient::class : PriceListTjClient::class);

                return $client->pricePage($download->external_id, $download->next_page, [...$download->snapshot['evidence'], 'received_rows' => $download->received_rows]);
            });
            $done = DB::transaction(function () use ($download, $worker, $page, $owner, $fence): bool {
                Organization::whereKey($download->supplier_organization_id)->lockForUpdate()->firstOrFail();
                $current = ExternalDownload::whereKey($download->id)->lockForUpdate()->firstOrFail();
                $this->lease->assertOwned($owner, $fence);
                if ($current->worker_token !== $worker || ! $current->worker_expires_at?->isFuture() || ! $this->configured($current)) {
                    throw new ExternalServiceUnavailable('configuration_changed');
                }
                if ($current->company !== null && ($current->company !== $page['company'] || $current->total_rows !== $page['total']
                    || $current->total_pages !== $page['total_pages'] || $current->account_discount !== $page['account_discount'])) {
                    throw new RuntimeException('catalog_changed');
                }
                if ($current->next_page === 1 && $current->external_availability_id) {
                    $observation = ExternalAvailability::findOrFail($current->external_availability_id);
                    $published = ExternalPriceWorkbook::publicationDate($page['company']['published_at'] ?? $page['company']['lastPriceUpdate'] ?? null);
                    if (($published['date']?->toDateTimeString() !== ExternalPriceWorkbook::publicationDate($observation->evidence['published_at'] ?? null)['date']?->toDateTimeString()) || $published['precision'] !== ExternalPriceWorkbook::publicationDate($observation->evidence['published_at'] ?? null)['precision']) {
                        throw new RuntimeException('catalog_changed');
                    }
                }
                if ($page['total'] < 1 || config('price-list-imports.max_rows') < $page['total'] + 1 || $page['total_pages'] < $current->next_page || $page['items'] === []) {
                    throw new RuntimeException('empty_or_incomplete_catalog');
                }
                $staged = DB::table('external_download_pages')->where('external_download_id', $current->id)->where('page', $current->next_page)->value('items');
                if ($staged !== null && json_decode($staged, true, 512, JSON_THROW_ON_ERROR) !== $page['items']) {
                    throw new RuntimeException('catalog_changed');
                }
                DB::table('external_download_pages')->insertOrIgnore(['external_download_id' => $current->id, 'page' => $current->next_page, 'items' => json_encode($page['items'], JSON_THROW_ON_ERROR)]);
                $received = $current->received_rows + count($page['items']);
                $complete = $current->next_page === $page['total_pages'];
                if ($received > $page['total'] || ($complete && $received !== $page['total'])) {
                    throw new RuntimeException('incomplete_pagination');
                }
                $current->update(['company' => $page['company'], 'account_discount' => $page['account_discount'], 'total_rows' => $page['total'], 'total_pages' => $page['total_pages'],
                    'next_page' => $current->next_page + 1, 'received_rows' => $received, 'status' => 'downloading', 'next_retry_at' => null]);

                return $complete;
            }, 3);
            if ($done) {
                $this->complete($download->fresh(), $worker, $owner, $fence);
            } else {
                $continue = true;
            }
        } catch (Throwable $exception) {
            $this->failure($download, $worker, $exception);
        } finally {
            ExternalDownload::whereKey($id)->where('worker_token', $worker)->update(['worker_token' => null, 'worker_expires_at' => null]);
            if ($fence !== null && ! $continue) {
                $this->lease->release($owner, $fence);
            }
        }
        if ($continue) {
            DownloadExternalPricePage::dispatch($id)->onQueue(config('price-list-imports.queue'));
        } else {
            $attempt = SupplierSyncAttempt::with('run')->findOrFail($download->supplier_sync_attempt_id);
            SynchronizeSupplier::dispatch($attempt->supplier_sync_run_id)->onQueue(config('price-list-imports.queue'));
        }
    }

    private function complete(ExternalDownload $download, string $worker, string $owner, int $fence): void
    {
        $items = [];
        $pages = DB::table('external_download_pages')->where('external_download_id', $download->id)->orderBy('page')->get();
        if ($pages->count() !== $download->total_pages || $download->next_page !== $download->total_pages + 1) {
            throw new RuntimeException('incomplete_pagination');
        }
        foreach ($pages as $index => $page) {
            if ($page->page !== $index + 1) {
                throw new RuntimeException('incomplete_pagination');
            }
            foreach (json_decode($page->items, true, 512, JSON_THROW_ON_ERROR) as $item) {
                if (isset($items[$item['external_id']])) {
                    throw new RuntimeException('repeated_rows');
                }
                $items[$item['external_id']] = $item;
            }
        }
        if (count($items) !== $download->total_rows) {
            throw new RuntimeException('incomplete_pagination');
        }
        $workbook = app(ExternalPriceWorkbook::class);
        $fingerprint = $workbook->fingerprint(array_values($items));
        $file = null;
        try {
            $duplicate = PriceListImport::where('supplier_organization_id', $download->supplier_organization_id)->where('source_type', $download->source)
                ->where('source_fingerprint', $fingerprint)->whereIn('status', [PriceListImportStatus::Completed, PriceListImportStatus::Superseded])->exists();
            if (! $duplicate) {
                $file = $workbook->store(array_values($items));
            }
            DB::transaction(function () use ($download, $worker, $owner, $fence, $file, $fingerprint, $duplicate): void {
                $supplier = Organization::whereKey($download->supplier_organization_id)->lockForUpdate()->firstOrFail();
                $current = ExternalDownload::whereKey($download->id)->lockForUpdate()->firstOrFail();
                $this->lease->assertOwned($owner, $fence);
                if ($current->worker_token !== $worker || ! $current->worker_expires_at?->isFuture() || ! $this->configured($current)) {
                    throw new ExternalServiceUnavailable('configuration_changed');
                }
                $attempt = SupplierSyncAttempt::with('run')->findOrFail($current->supplier_sync_attempt_id);
                $publication = ExternalPriceWorkbook::publicationDate($current->snapshot['evidence']['confirmed_published_at'] ?? $current->company['published_at'] ?? $current->company['lastPriceUpdate'] ?? null);
                $metadata = ['partner_id' => $current->snapshot['partner_id'], 'external_id' => $current->external_id, 'profile_version' => $current->snapshot['profile_version'],
                    'configuration_version' => $current->snapshot['configuration_version'], 'trigger' => $attempt->run->trigger, 'fingerprint' => $fingerprint,
                    'published_at' => $publication['date']?->toIso8601String(), 'date_precision' => $publication['precision'], 'extracted_at' => now()->toIso8601String(),
                    'company' => $current->company, 'account_discount' => $current->account_discount, 'download_id' => $current->id, ...array_intersect_key($current->snapshot['evidence'], array_flip(['date_confirmed_by', 'date_evidence']))];
                $attempt->update(['metadata' => $metadata]);
                if ($duplicate) {
                    $attempt->update(['status' => 'skipped', 'reason' => 'unchanged_content']);
                } else {
                    $import = app(SupplierPriceListIngestor::class)->ingest($supplier, $file, new IngestionContext(PriceListImportSource::from($current->source),
                        profileConfiguration: ExternalPriceWorkbook::profile($current->snapshot['profile']), sourceMetadata: $metadata,
                        sourcePublishedAt: $publication['date'], sourceDatePrecision: $publication['precision'], sourceFingerprint: $fingerprint));
                    $attempt->update(['price_list_import_id' => $import->id, 'status' => 'processing']);
                }
                $current->update(['status' => 'completed', 'reason' => $duplicate ? 'unchanged_content' : null]);
                if ($current->external_availability_id) {
                    ExternalAvailability::whereKey($current->external_availability_id)->update(['last_downloaded_at' => now()]);
                }
                DB::table('external_download_pages')->where('external_download_id', $current->id)->delete();
            }, 3);
        } catch (Throwable $exception) {
            if ($file && ! PriceListImport::where('file_path', $file->path)->exists()) {
                Storage::disk($file->disk)->delete($file->path);
            }
            throw $exception;
        }
    }

    private function failure(ExternalDownload $download, string $worker, Throwable $exception): void
    {
        $reason = $exception instanceof ExternalServiceUnavailable ? $exception->reason : ExternalSupplierDirectory::failureReason($exception);
        $current = ExternalDownload::whereKey($download->id)->where('worker_token', $worker)->first();
        if (! $current) {
            return;
        }
        $attempt = SupplierSyncAttempt::findOrFail($current->supplier_sync_attempt_id);
        $busy = $exception instanceof LockTimeoutException || in_array($reason, ['download_busy', 'lease_lost'], true);
        $transient = $reason === 'transport';
        $state = ExternalServiceState::findOrFail($download->source);
        $retry = $current->retry_count + ($transient ? 1 : 0);
        $delay = $busy ? 60 : ($exception instanceof ExternalServiceUnavailable && $exception->retryAfter ? $exception->retryAfter : $this->control->retryDelay($retry));
        $next = now()->addSeconds($delay);
        $status = match (true) {
            $reason === 'configuration_changed' => 'cancelled',
            $busy, $transient && $retry <= 3 => 'retry',
            $transient => 'cooldown',
            $state->status === 'paused' => 'paused',
            in_array($reason, ['service_cooldown', 'rate_limited', 'pacing_wait'], true) => 'retry',
            default => 'failed',
        };
        $current->update(['status' => $status, 'reason' => $reason, 'retry_count' => $retry, 'next_retry_at' => in_array($status, ['retry', 'cooldown'], true) ? $next : null]);
        $attempt->update(['status' => in_array($status, ['retry', 'cooldown', 'paused'], true) ? $status : 'failed', 'reason' => $reason, 'retry_count' => $retry,
            'next_retry_at' => $current->next_retry_at, 'metadata' => array_replace($attempt->metadata ?? [], ['failure' => $exception instanceof IcorpFailure ? $exception->diagnostic() : ['stage' => 'download', 'code' => $reason, 'http_status' => null]])]);
        if ($transient && $retry > 3) {
            $state->update(['cooldown_until' => $next, 'reason' => 'retry_exhausted']);
            $this->control->incident($download->source, 'retry_exhausted');
        }
    }
}
