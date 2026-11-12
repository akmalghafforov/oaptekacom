<?php

namespace App\Services;

use App\DataTransferObjects\IngestionContext;
use App\Enums\PriceListImportSource;
use App\Enums\PriceListImportStatus;
use App\Jobs\SynchronizeSupplier;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSyncAttempt;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use App\Services\PriceList\ActivationDispatcher;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupplierSynchronizationService
{
    public function request(Organization $supplier, string $trigger = 'manual', ?int $expectedVersion = null, ?string $expectedSource = null): SupplierSyncRun
    {
        if (Cache::get('supplier-imports:dispatch-paused', false)) {
            throw ValidationException::withMessages(['selected_source' => 'Новые импорты приостановлены на время переключения источников.']);
        }

        return DB::transaction(function () use ($supplier, $trigger, $expectedVersion, $expectedSource): SupplierSyncRun {
            $supplier = Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            if (Cache::get('supplier-imports:dispatch-paused', false)) {
                throw ValidationException::withMessages(['selected_source' => 'Новые импорты приостановлены на время переключения источников.']);
            }
            $config = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->firstOrFail();
            if ($expectedVersion !== null && ($config->version !== $expectedVersion || $config->selected_source !== $expectedSource)) {
                throw ValidationException::withMessages(['expected_version' => 'Настройки источника изменились. Обновите страницу перед импортом.']);
            }
            if ($expectedVersion !== null && ($supplier->status !== 'active' || app(SupplierImportSettings::class)->readinessReason($supplier, $config->selected_source, true) !== null)) {
                throw ValidationException::withMessages(['expected_source' => 'Импорт недоступен. Проверьте статус поставщика, сопоставление, профиль и подключение источника.']);
            }
            if (($config->usesExclusiveSource() || ! $config->settings_confirmed) && $trigger === 'manual' && app(SupplierImportSettings::class)->readinessReason($supplier, $config->selected_source) !== null) {
                throw ValidationException::withMessages(['selected_source' => 'Настройте выбранный источник в профиле импорта перед ручной загрузкой.']);
            }
            $run = SupplierSyncRun::where('active_supplier_id', $supplier->id)->first();
            if (! $run) {
                $run = SupplierSyncRun::create(['supplier_organization_id' => $supplier->id, 'active_supplier_id' => $supplier->id,
                    'trigger' => $trigger, 'configuration_snapshot' => $config->toArray()]);
            }
            if ($trigger === 'manual' && $run->trigger !== 'manual') {
                $run->update(['trigger' => 'manual']);
                foreach ($run->attempts()->with('import')->get() as $attempt) {
                    if ($attempt->import) {
                        $attempt->import->update(['source_metadata' => array_replace($attempt->import->source_metadata ?? [], ['trigger' => 'manual'])]);
                    }
                }
            }
            SynchronizeSupplier::dispatch($run->id)->onQueue(config('price-list-imports.queue'))->afterCommit();

            return $run;
        }, 3);
    }

    public function recoverStaleImport(PriceListImport $import): bool
    {
        return DB::transaction(function () use ($import): bool {
            $supplier = Organization::whereKey($import->supplier_organization_id)->lockForUpdate()->firstOrFail();
            $import = PriceListImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->first();
            if (! $configuration?->usesExclusiveSource() || ! in_array($import->source_type->value, ['icorp', 'pricelist_tj'], true)
                || ! in_array($import->status, [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Committing], true)
                || ($import->source_metadata['configuration_version'] ?? null) === $configuration->version) {
                return false;
            }
            $import->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'source_configuration_changed', 'failure_message' => 'configuration_changed', 'failed_at' => now()]);
            if (app(SupplierImportSettings::class)->readinessReason($supplier, $configuration->selected_source) === null) {
                $this->request($supplier, ($import->source_metadata['trigger'] ?? 'automatic') === 'manual' ? 'manual' : 'automatic');
            }

            return true;
        }, 3);
    }

    public function process(SupplierSyncRun $run): void
    {
        $lock = Cache::lock('supplier-synchronization:'.$run->supplier_organization_id, 7200);
        if (! $lock->get()) {
            return;
        }
        $token = (string) Str::uuid();
        try {
            $claimed = DB::transaction(function () use ($run, $token): bool {
                Organization::whereKey($run->supplier_organization_id)->lockForUpdate()->firstOrFail();
                $run = SupplierSyncRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
                if (! $run->active_supplier_id || ($run->owner_token && $run->lease_expires_at?->isFuture())) {
                    return false;
                }
                $run->update(['owner_token' => $token, 'lease_expires_at' => now()->addSeconds(7200)]);

                return true;
            }, 3);
            if (! $claimed) {
                return;
            }
            $this->advance($run->fresh());
        } finally {
            SupplierSyncRun::whereKey($run->id)->where('owner_token', $token)->update(['owner_token' => null, 'lease_expires_at' => null]);
            $lock->release();
        }
    }

    private function advance(SupplierSyncRun $run): void
    {
        $config = SupplierSynchronization::where('supplier_organization_id', $run->supplier_organization_id)->firstOrFail();
        if (($run->trigger !== 'manual' && ($config->paused || ! $config->automatic_enabled)) || $run->configuration_snapshot['version'] !== $config->version) {
            $this->finish($run, 'skipped', $config->paused ? 'paused' : 'configuration_changed');
            if ($run->trigger === 'manual' && $run->configuration_snapshot['version'] !== $config->version) {
                $this->request($run->supplier, 'manual');
            }

            return;
        }
        if ($config->usesExclusiveSource() && ($reason = app(SupplierImportSettings::class)->readinessReason($run->supplier, $config->selected_source)) !== null) {
            $this->finish($run, 'skipped', $reason, $config->selected_source);

            return;
        }
        $ownImports = $run->attempts()->whereNotNull('price_list_import_id')->pluck('price_list_import_id');
        if (PriceListImport::where('supplier_organization_id', $run->supplier_organization_id)
            ->whereIn('source_type', ['icorp', 'pricelist_tj'])->whereNotIn('id', $ownImports)
            ->whereIn('status', [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Committing])->exists()) {
            $run->update(['status' => 'waiting_import']);

            return;
        }
        $policy = app(SupplierSourcePolicy::class);
        $review = PriceListImport::where('supplier_organization_id', $run->supplier_organization_id)
            ->where('status', PriceListImportStatus::Preview)->whereNotNull('source_metadata')->get()
            ->first(fn (PriceListImport $candidate): bool => str_starts_with($policy->reason($candidate) ?? '', 'review_'));
        if ($review && ! $run->attempts()->where('price_list_import_id', $review->id)->exists()) {
            $run->update(['status' => 'review', 'reason' => 'review_pending']);

            return;
        }
        foreach ($config->enabledSources() as $source) {
            $attempt = $run->attempts()->where('source', $source)->first();
            if ($attempt?->price_list_import_id) {
                $import = $attempt->import;
                if ($import->status === PriceListImportStatus::Completed) {
                    $attempt->update(['status' => 'activated', 'reason' => null]);
                    $this->finish($run, 'completed', 'activated', $source);

                    return;
                }
                if (in_array($import->status, [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Committing], true)) {
                    $run->update(['status' => 'processing']);

                    return;
                }
                if ($import->status === PriceListImportStatus::Preview && ! in_array($attempt->status, ['skipped', 'failed'], true)) {
                    $reason = $policy->reason($import);
                    if ($reason === null) {
                        $attempt->update(['status' => 'validated', 'reason' => null]);
                        $run->update(['status' => 'activating', 'selected_source' => $source]);
                        app(ActivationDispatcher::class)->dispatch($import);
                        if ($import->fresh()->status === PriceListImportStatus::Completed) {
                            $attempt->update(['status' => 'activated']);
                            $this->finish($run, 'completed', 'activated', $source);
                        }

                        return;
                    }
                    if (str_starts_with($reason, 'review_') || $reason === 'preferred_unresolved') {
                        $attempt->update(['status' => 'review', 'reason' => $reason]);
                        $run->update(['status' => 'review', 'reason' => $reason]);

                        return;
                    }
                    $attempt->update(['status' => 'skipped', 'reason' => $reason]);
                } elseif ($import->status === PriceListImportStatus::Failed) {
                    $attempt->update(['status' => 'failed', 'reason' => 'import_failed']);
                }
            } elseif ($attempt && in_array($attempt->status, ['skipped', 'failed'], true)) {
                // A failed attempt is retried in the next run, never in an unbounded loop.
            } elseif ($source === 'email') {
                if ($policy->unresolved($run->supplier_organization_id, ['email'])) {
                    $run->update(['status' => 'waiting_mailbox']);

                    return;
                }
                $attempt ??= $run->attempts()->create(['source' => $source]);
                $attempt->update(['status' => 'skipped', 'reason' => 'no_new_mailbox_list']);
                if ($config->usesExclusiveSource()) {
                    $this->finish($run, 'unchanged', 'no_new_mailbox_list', 'email');

                    return;
                }
            } else {
                $attempt ??= $run->attempts()->create(['source' => $source]);
                try {
                    $this->extract($run, $attempt, $config);
                } catch (LockTimeoutException) {
                    $attempt->delete();
                    $run->update(['status' => 'queued', 'reason' => 'service_busy']);

                    return;
                } catch (Throwable $exception) {
                    $attempt->update(['status' => 'failed', 'reason' => ExternalSupplierDirectory::failureReason($exception)]);
                }
                if ($attempt->fresh()->price_list_import_id) {
                    $run->update(['status' => 'processing']);
                    $this->advance($run->fresh());

                    return;
                }
            }
            if ($policy->freshValidated($run->supplier_organization_id, $source, $config->freshness_hours)) {
                $this->finish($run, 'unchanged', 'preferred_fresh', $source);

                return;
            }
        }
        $this->finish($run, 'unchanged', $config->selected_source === 'email' ? 'no_new_mailbox_list' : 'no_eligible_candidate', $config->selected_source);
    }

    private function extract(SupplierSyncRun $run, SupplierSyncAttempt $attempt, SupplierSynchronization $config): void
    {
        $source = $attempt->source;
        $partner = ExternalSupplierPartner::where('supplier_organization_id', $run->supplier_organization_id)->where('source', $source)->first();
        if (! $partner || ! $partner->profile_active || ! $partner->profile_configuration) {
            $attempt->update(['status' => 'skipped', 'reason' => $partner ? 'missing_profile' : 'unmapped']);

            return;
        }
        $profile = ExternalPriceWorkbook::profile($partner->profile_configuration);
        $prices = Cache::lock('external-prices:'.$source, 7200)->block(1, function () use ($source, $partner): array {
            $client = app($source === 'icorp' ? IcorpClient::class : PriceListTjClient::class);
            if ($source === 'icorp') {
                $partners = $client->partners();
                $metadata = collect($partners)->firstWhere('external_id', $partner->external_id);
                if ($metadata) {
                    DB::transaction(function () use ($partner, $metadata): void {
                        $current = ExternalSupplierPartner::whereKey($partner->id)->lockForUpdate()->firstOrFail();
                        $current->update(['metadata' => $current->directoryMetadata($metadata), 'discovered_at' => now()]);
                    });
                }
            }

            return $client->prices($partner->external_id);
        });
        if (($prices['unavailable'] ?? false) || $prices['items'] === []) {
            $attempt->update(['status' => 'failed', 'reason' => 'empty_catalog']);

            return;
        }
        $workbook = app(ExternalPriceWorkbook::class);
        $fingerprint = $workbook->fingerprint($prices['items']);
        $publication = ExternalPriceWorkbook::publicationDate($prices['company']['published_at'] ?? $prices['company']['lastPriceUpdate'] ?? null);
        $metadata = ['partner_id' => $partner->id, 'external_id' => $partner->external_id, 'profile_version' => $partner->profile_version,
            'configuration_version' => $config->version, 'trigger' => $run->fresh()->trigger, 'fingerprint' => $fingerprint,
            'published_at' => $publication['date']?->toIso8601String(), 'date_precision' => $publication['precision'],
            'extracted_at' => now()->toIso8601String(), 'company' => $prices['company'], 'account_discount' => $prices['account_discount'] ?? null];
        $attempt->update(['metadata' => $metadata]);
        if (PriceListImport::where('supplier_organization_id', $run->supplier_organization_id)->where('source_type', $source)
            ->where('source_fingerprint', $fingerprint)->whereIn('status', [PriceListImportStatus::Completed, PriceListImportStatus::Superseded])->exists()) {
            $attempt->update(['status' => 'skipped', 'reason' => 'unchanged_content']);

            return;
        }
        $file = $workbook->store($prices['items']);
        try {
            DB::transaction(function () use ($run, $attempt, $file, $source, $profile, $metadata, $publication, $fingerprint): void {
                $import = app(SupplierPriceListIngestor::class)->ingest($run->supplier, $file,
                    new IngestionContext(PriceListImportSource::from($source), profileConfiguration: $profile, sourceMetadata: $metadata,
                        sourcePublishedAt: $publication['date'], sourceDatePrecision: $publication['precision'], sourceFingerprint: $fingerprint));
                $attempt->update(['price_list_import_id' => $import->id, 'status' => 'processing']);
            });
        } catch (Throwable $exception) {
            Storage::disk($file->disk)->delete($file->path);
            throw $exception;
        }
    }

    private function finish(SupplierSyncRun $run, string $status, string $reason, ?string $source = null): void
    {
        $run->update(['status' => $status, 'reason' => $reason, 'selected_source' => $source, 'active_supplier_id' => null, 'finished_at' => now()]);
        $config = SupplierSynchronization::where('supplier_organization_id', $run->supplier_organization_id)->firstOrFail();
        $config->update(['next_check_at' => now()->addMinutes($config->fetch_interval_minutes)]);
    }
}
