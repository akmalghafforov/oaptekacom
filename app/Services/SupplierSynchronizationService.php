<?php

namespace App\Services;

use App\Enums\PriceListImportStatus;
use App\Jobs\SynchronizeSupplier;
use App\Models\ExternalAvailability;
use App\Models\ExternalDownload;
use App\Models\ExternalListingRun;
use App\Models\ExternalServiceState;
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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupplierSynchronizationService
{
    public function request(Organization $supplier, string $trigger = 'manual', ?int $expectedVersion = null, ?string $expectedSource = null, ?int $requesterId = null, ?string $groupId = null): SupplierSyncRun
    {
        if (app(SupplierVersions::class)->paused()) {
            throw ValidationException::withMessages(['selected_source' => 'Новые импорты приостановлены на время переключения источников.']);
        }

        $queueRequester = $trigger === 'manual' ? ($requesterId ?? auth()->id()) : null;
        $queueLane = $queueRequester === null ? 'system' : 'user:'.$queueRequester;
        DB::table('external_import_queue_lanes')->insertOrIgnore(['id' => $queueLane]);

        return DB::transaction(function () use ($supplier, $trigger, $expectedVersion, $expectedSource, $requesterId, $groupId, $queueLane): SupplierSyncRun {
            DB::table('external_import_queue_lanes')->where('id', $queueLane)->lockForUpdate()->first();
            $supplier = Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
            if (app(SupplierVersions::class)->paused()) {
                throw ValidationException::withMessages(['selected_source' => 'Новые импорты приостановлены на время переключения источников.']);
            }
            $config = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->firstOrFail();
            if (! app(SupplierVersions::class)->enabled() && $expectedVersion !== null && ($config->version !== $expectedVersion || $config->selected_source !== $expectedSource)) {
                throw ValidationException::withMessages(['expected_version' => 'Настройки источника изменились. Обновите страницу перед импортом.']);
            }
            if (! app(SupplierVersions::class)->enabled() && $expectedVersion !== null && ($supplier->status !== 'active' || app(SupplierImportSettings::class)->readinessReason($supplier, $config->selected_source, true) !== null)) {
                throw ValidationException::withMessages(['expected_source' => 'Импорт недоступен. Проверьте статус поставщика, сопоставление, профиль и подключение источника.']);
            }
            if (! app(SupplierVersions::class)->enabled() && ($config->usesExclusiveSource() || ! $config->settings_confirmed) && $trigger === 'manual' && app(SupplierImportSettings::class)->readinessReason($supplier, $config->selected_source) !== null) {
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
            if (array_intersect($config->enabledSources(), ['icorp', 'pricelist_tj']) !== []) {
                $item = app(ExternalImportQueue::class)->enqueue($run, $trigger === 'manual' ? ($requesterId ?? auth()->id()) : null, $groupId);
                DB::afterCommit(fn () => app(ExternalImportQueue::class)->dispatchLane($item->lane_id));
            } else {
                SynchronizeSupplier::dispatch($run->id)->onQueue(config('price-list-imports.queue'))->afterCommit();
            }

            return $run;
        }, 3);
    }

    public function recoverStaleImport(PriceListImport $import): bool
    {
        return DB::transaction(function () use ($import): bool {
            $supplier = Organization::whereKey($import->supplier_organization_id)->lockForUpdate()->firstOrFail();
            $import = PriceListImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            $configuration = SupplierSynchronization::where('supplier_organization_id', $supplier->id)->first();
            if ((! $configuration?->usesExclusiveSource() && ! $configuration?->usesNewestAvailable()) || ! in_array($import->source_type->value, ['icorp', 'pricelist_tj'], true)
                || ! in_array($import->status, [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Committing], true)
                || ($import->source_metadata['configuration_version'] ?? null) === $configuration->version) {
                return false;
            }
            $import->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'source_configuration_changed', 'failure_message' => 'configuration_changed', 'failed_at' => now()]);
            if ($configuration->usesNewestAvailable() || app(SupplierImportSettings::class)->readinessReason($supplier, $configuration->selected_source) === null) {
                $this->request($supplier, ($import->source_metadata['trigger'] ?? 'automatic') === 'manual' ? 'manual' : 'automatic');
            }

            return true;
        }, 3);
    }

    public function process(SupplierSyncRun $run, ?int $generation = null): void
    {
        $run = $run->fresh();
        $enabledSources = array_keys(array_filter($run->configuration_snapshot['sources'] ?? [], fn (array $source): bool => (bool) ($source['enabled'] ?? false)));
        $hasExternalSource = in_array($run->configuration_snapshot['selected_source'] ?? null, ['icorp', 'pricelist_tj'], true) || array_intersect($enabledSources, ['icorp', 'pricelist_tj']) !== [];
        if ($run->execution_queue_item_id || ($run->trigger !== 'mailbox' && $hasExternalSource)) {
            app(ExternalImportQueue::class)->execute($run, $generation ?? app(ExternalImportQueue::class)->generation($run->id), fn () => $this->processOwned($run));

            return;
        }
        $this->processOwned($run);
    }

    private function processOwned(SupplierSyncRun $run): void
    {
        $lock = Cache::lock('supplier-synchronization:'.$run->supplier_organization_id, 140);
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
                $run->update(['owner_token' => $token, 'lease_expires_at' => now()->addSeconds(140)]);

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
        if (app(SupplierVersions::class)->enabled()) {
            $this->advanceDaily($run, $config);

            return;
        }
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
        if ($config->usesNewestAvailable()) {
            $this->advanceNewest($run, $config);

            return;
        }
        $review = PriceListImport::where('supplier_organization_id', $run->supplier_organization_id)
            ->where('status', PriceListImportStatus::Preview)->whereNotNull('source_metadata')->get()
            ->first(fn (PriceListImport $candidate): bool => str_starts_with($policy->reason($candidate) ?? '', 'review_'));
        if ($review && ! $run->attempts()->where('price_list_import_id', $review->id)->exists()) {
            $run->update(['status' => 'review', 'reason' => 'review_pending']);

            return;
        }
        foreach ($config->enabledSources() as $source) {
            $attempt = $run->attempts()->where('source', $source)->first();
            if ($attempt && in_array($attempt->status, ['queued', 'downloading', 'retry', 'cooldown', 'paused'], true)) {
                $run->update(['status' => $attempt->status, 'next_retry_at' => $attempt->next_retry_at]);

                return;
            }
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
                if ($import->status === PriceListImportStatus::Preview && ! in_array($attempt->status, ['skipped', 'failed', 'retry', 'cooldown', 'paused'], true)) {
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
            } elseif ($attempt && in_array($attempt->status, ['skipped', 'failed', 'retry', 'cooldown', 'paused'], true)) {
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
                } catch (ExternalServiceUnavailable $exception) {
                    if (in_array($exception->reason, ['availability_pending', 'service_retry_pending'], true)) {
                        $attempt->delete();
                        $run->update(['status' => 'waiting_availability', 'reason' => $exception->reason, 'next_retry_at' => now()->addMinute()]);

                        return;
                    }
                    $attempt->update(['status' => 'failed', 'reason' => $exception->reason, 'metadata' => ['failure' => ['stage' => 'directory', 'code' => $exception->reason, 'http_status' => null]]]);
                } catch (Throwable $exception) {
                    $attempt->update(['status' => 'failed', 'reason' => ExternalSupplierDirectory::failureReason($exception),
                        'metadata' => array_replace($attempt->metadata ?? [], ['failure' => $exception instanceof IcorpFailure ? $exception->diagnostic() : ['stage' => 'download', 'code' => ExternalSupplierDirectory::failureReason($exception), 'http_status' => null]])]);
                }
                if (in_array($attempt->fresh()->status, ['queued', 'downloading', 'retry', 'cooldown', 'paused'], true)) {
                    $run->update(['status' => $attempt->status, 'next_retry_at' => $attempt->next_retry_at]);

                    return;
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
        $observation = null;
        if ($source === 'icorp') {
            app(ExternalSupplierDirectory::class)->availability($source);
            $observation = ExternalAvailability::where('external_supplier_partner_id', $partner->id)->orderByDesc('last_checked_at')->orderByDesc('id')->first();
            if (! $observation?->available) {
                $attempt->update(['status' => 'skipped', 'reason' => 'price_list_unavailable']);

                return;
            }
        }
        app(ExternalPriceDownload::class)->start($attempt, $config, $partner, $observation);
    }

    private function advanceNewest(SupplierSyncRun $run, SupplierSynchronization $config): void
    {
        if ($run->supplier->status !== 'active') {
            $this->finish($run, 'skipped', 'inactive_supplier');

            return;
        }
        $policy = app(SupplierSourcePolicy::class);
        foreach ($run->attempts()->with('import')->get() as $attempt) {
            if ($attempt->import?->status === PriceListImportStatus::Completed) {
                $this->finish($run, 'completed', 'activated', $attempt->source);

                return;
            }
            if ($attempt->import && in_array($attempt->import->status, [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Committing], true)) {
                $run->update(['status' => 'processing']);

                return;
            }
            if (in_array($attempt->status, ['queued', 'downloading', 'retry', 'cooldown', 'paused'], true)) {
                $run->update(['status' => $attempt->status, 'next_retry_at' => $attempt->next_retry_at]);

                return;
            }
        }
        if (in_array('email', $config->enabledSources(), true)) {
            $health = ExternalServiceState::findOrFail('email');
            $pending = app(NewestSupplierCandidates::class)->mailboxPending($run->supplier_organization_id);
            if ($pending || $health->status !== 'ready' || ! $health->last_success_at || $health->last_success_at->lt(now()->subMinutes(15))) {
                if (! $run->mailbox_wait_started_at) {
                    $run->update(['mailbox_wait_started_at' => now()]);
                }
                if ($run->mailbox_wait_started_at->gt(now()->subMinutes(15))) {
                    $run->update(['status' => 'waiting_mailbox', 'reason' => $pending ? 'mailbox_processing' : 'mailbox_unhealthy', 'next_retry_at' => now()->addMinute()]);

                    return;
                }
            }
        }
        $availabilityPending = false;
        foreach (array_diff($config->enabledSources(), ['email']) as $source) {
            try {
                app(ExternalSupplierDirectory::class)->availability($source);
            } catch (Throwable $exception) {
                if ($exception instanceof ExternalServiceUnavailable && in_array($exception->reason, ['availability_pending', 'service_retry_pending'], true)) {
                    $availabilityPending = true;
                }
                $attempt = $run->attempts()->firstOrCreate(['source' => $source]);
                $attempt->update(['status' => 'skipped', 'reason' => $exception instanceof ExternalServiceUnavailable ? $exception->reason : ExternalSupplierDirectory::failureReason($exception)]);
            }
        }
        $candidates = app(NewestSupplierCandidates::class);
        $entries = $candidates->ordered($config, $candidates->known($config));
        $run->update(['decision' => ['mode' => 'newest_available', 'candidates' => array_map(fn (array $entry): array => [
            'source' => $entry['source'], 'published_at' => $entry['date']?->toIso8601String(), 'precision' => $entry['precision'], 'review' => $entry['review'],
        ], $entries)], 'next_retry_at' => null]);
        foreach ($entries as $entry) {
            $source = $entry['source'];
            $attempt = $run->attempts()->firstOrCreate(['source' => $source]);
            if ($entry['review']) {
                $attempt->update(['status' => 'review', 'reason' => $entry['review'], 'metadata' => array_replace($attempt->metadata ?? [], [
                    'published_at' => $entry['date']?->toIso8601String(), 'date_precision' => $entry['precision'], 'availability_id' => $entry['availability']->id ?? null])]);

                continue;
            }
            if (isset($entry['import'])) {
                $import = $entry['import'];
                if ($run->trigger === 'manual') {
                    $import = DB::transaction(function () use ($import): PriceListImport {
                        Organization::whereKey($import->supplier_organization_id)->lockForUpdate()->firstOrFail();
                        $current = PriceListImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
                        if ($current->status === PriceListImportStatus::Preview) {
                            $current->update(['source_metadata' => array_replace($current->source_metadata ?? [], ['trigger' => 'manual'])]);
                        }

                        return $current;
                    }, 3);
                }
                $reason = $policy->reason($import);
                $attempt->update(['price_list_import_id' => $import->id, 'status' => $reason === null ? 'validated' : (str_starts_with($reason, 'review_') ? 'review' : 'skipped'), 'reason' => $reason]);
                if ($reason !== null) {
                    continue;
                }
                $run->update(['status' => 'activating', 'selected_source' => $source, 'reason' => 'newest_publication']);
                app(ActivationDispatcher::class)->dispatch($import);

                return;
            }
            if ($attempt->status === 'review' && ! $attempt->price_list_import_id) {
                $attempt->update(['status' => 'fetching', 'reason' => null]);
            }
            if (in_array($attempt->status, ['failed', 'review'], true)) {
                continue;
            }
            try {
                app(ExternalServiceControl::class)->assertAvailable($source);
                $partner = ExternalSupplierPartner::findOrFail($entry['availability']->external_supplier_partner_id);
                app(ExternalPriceDownload::class)->start($attempt, $config, $partner, $entry['availability']);
                if (! $run->fresh()->active_supplier_id) {
                    return;
                }
                if (in_array($attempt->fresh()->status, ['queued', 'downloading', 'retry', 'cooldown', 'paused', 'processing'], true)) {
                    $run->update(['status' => $attempt->fresh()->status, 'selected_source' => $source, 'reason' => 'newest_publication']);

                    return;
                }
            } catch (Throwable $exception) {
                $attempt->update(['status' => 'failed', 'reason' => $exception instanceof ExternalServiceUnavailable ? $exception->reason : ExternalSupplierDirectory::failureReason($exception)]);
            }
        }
        if ($availabilityPending) {
            $run->update(['status' => 'waiting_availability', 'reason' => 'availability_pending', 'next_retry_at' => now()->addMinute()]);

            return;
        }
        $needsReview = $run->attempts()->where('status', 'review')->exists();
        $this->finish($run, $needsReview ? 'review' : 'unchanged', $needsReview ? 'review_pending' : 'no_eligible_candidate');
    }

    private function advanceDaily(SupplierSyncRun $run, SupplierSynchronization $config): void
    {
        $supplier = $run->supplier->fresh();
        if (app(SupplierVersions::class)->paused() || ! $supplier->external_fetch_enabled || $supplier->status !== 'active' || ! $config->automatic_enabled || $config->paused) {
            $this->finish($run, 'skipped', 'external_fetch_disabled');

            return;
        }
        if (($run->configuration_snapshot['version'] ?? null) !== $config->version) {
            $this->finish($run, 'skipped', 'configuration_changed');

            return;
        }
        foreach ($run->attempts()->with('import')->get() as $attempt) {
            if ($attempt->import && in_array($attempt->import->status, [PriceListImportStatus::Completed, PriceListImportStatus::Superseded], true)) {
                $this->finish($run, 'completed', 'activated', $attempt->source);

                return;
            }
            if ($attempt->import?->source_published_at && app(SupplierVersions::class)->freshnessReason($supplier, $attempt->import->source_published_at) === 'already_imported_day') {
                DB::transaction(function () use ($supplier, $attempt): void {
                    Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                    PriceListImport::whereKey($attempt->price_list_import_id)->whereNotIn('status', [PriceListImportStatus::Completed, PriceListImportStatus::Superseded])
                        ->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'version_skipped', 'failure_message' => 'already_imported_day', 'failed_at' => now()]);
                    $attempt->update(['status' => 'skipped', 'reason' => 'already_imported_day']);
                });
                $this->finish($run, 'unchanged', 'already_imported_day');

                return;
            }
            if ($attempt->import?->status === PriceListImportStatus::Preview && ($reason = app(SupplierSourcePolicy::class)->reason($attempt->import)) !== null) {
                if ($reason === 'validation_failed') {
                    DB::transaction(function () use ($supplier, $attempt): void {
                        Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                        $attempt->import->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'validation', 'failure_message' => 'validation_failed', 'failed_at' => now()]);
                        $attempt->update(['status' => 'failed', 'reason' => 'validation_failed']);
                    });

                    continue;
                }
                $attempt->update(['status' => 'skipped', 'reason' => $reason]);
                $this->finish($run, 'skipped', $reason);

                return;
            }
            if ($attempt->import && in_array($attempt->import->status, [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Preview, PriceListImportStatus::Committing], true)) {
                app(ActivationDispatcher::class)->dispatch($attempt->import);
                $run->update(['status' => 'processing']);

                return;
            }
            if (in_array($attempt->status, ['queued', 'downloading', 'retry', 'cooldown', 'paused'], true)) {
                $run->update(['status' => $attempt->status, 'next_retry_at' => $attempt->next_retry_at]);

                return;
            }
        }
        $versions = app(SupplierVersions::class);
        $entries = [];
        $partners = ExternalSupplierPartner::where('supplier_organization_id', $supplier->id)->get()->keyBy('source');
        foreach (['pricelist_tj', 'icorp'] as $source) {
            $partner = $partners->get($source);
            $runEvidence = ExternalListingRun::where('source', $source)->where('scan_hour', now()->startOfHour())->where('status', 'completed')->first();
            $state = ExternalServiceState::find($source);
            $observation = $partner ? ExternalAvailability::where('external_supplier_partner_id', $partner->id)->where('last_checked_at', '>=', now()->startOfHour())->orderByDesc('last_checked_at')->orderByDesc('id')->first() : null;
            $reason = match (true) {
                ! $partner => 'unmapped',
                ! $partner->profile_active || ! $partner->profile_configuration => 'missing_profile',
                $state?->status === 'paused' => $state->reason ?? 'source_paused',
                $state?->cooldown_until?->isFuture() => 'service_cooldown',
                ! $runEvidence || ($observation?->evidence['listing_run_id'] ?? null) !== $runEvidence->id => 'listing_unavailable',
                ! $observation?->available => 'price_list_unavailable',
                ! $observation->published_at => 'missing_listing_date',
                default => $versions->freshnessReason($supplier, $observation->published_at),
            };
            if ($reason) {
                $attempt = $run->attempts()->firstOrCreate(['source' => $source]);
                if (! in_array($attempt->status, ['failed', 'activated'], true)) {
                    $attempt->update(['status' => 'skipped', 'reason' => $reason]);
                }

                continue;
            }
            $key = hash('sha256', $supplier->id.'|'.$source.'|'.$versions->day($observation->published_at));
            if ($existing = ExternalDownload::where('supplier_organization_id', $supplier->id)->where('source', $source)->where('version_key', $key)->first()) {
                $run->attempts()->firstOrCreate(['source' => $source], ['status' => 'skipped', 'reason' => 'version_attempt_recorded', 'metadata' => ['download_id' => $existing->id]]);

                continue;
            }
            $entries[] = ['partner' => $partner, 'observation' => $observation, 'day' => $versions->day($observation->published_at)];
        }
        usort($entries, fn (array $a, array $b): int => strcmp($b['day'], $a['day']) ?: (($a['partner']->source === 'pricelist_tj' ? 0 : 1) <=> ($b['partner']->source === 'pricelist_tj' ? 0 : 1)));
        $run->update(['decision' => ['mode' => 'daily_versions', 'candidates' => array_map(fn (array $entry): array => ['source' => $entry['partner']->source, 'day' => $entry['day']], $entries)]]);
        foreach ($entries as $entry) {
            if (app(SupplierInboxEvidence::class)->exists($supplier->id, $entry['observation']->published_at)) {
                app(SupplierInboxEvidence::class)->reserve($supplier, $entry['observation']->published_at);
                $this->finish($run, 'unchanged', 'reserved_for_inbox', 'email');

                return;
            }
            $attempt = $run->attempts()->firstOrCreate(['source' => $entry['partner']->source]);
            try {
                app(ExternalPriceDownload::class)->start($attempt, $config, $entry['partner'], $entry['observation']);
            } catch (ExternalServiceUnavailable $exception) {
                $attempt->update(['status' => 'skipped', 'reason' => $exception->reason]);

                continue;
            }
            if (in_array($attempt->fresh()->status, ['queued', 'downloading', 'retry', 'cooldown', 'paused'], true)) {
                $run->update(['status' => $attempt->fresh()->status, 'selected_source' => $entry['partner']->source]);

                return;
            }
        }
        $this->finish($run, 'unchanged', 'no_eligible_candidate');
    }

    private function finish(SupplierSyncRun $run, string $status, string $reason, ?string $source = null): void
    {
        $run->update(['status' => $status, 'reason' => $reason, 'selected_source' => $source, 'active_supplier_id' => null, 'finished_at' => now(), 'next_retry_at' => null]);
        $config = SupplierSynchronization::where('supplier_organization_id', $run->supplier_organization_id)->firstOrFail();
        $config->update(['next_check_at' => now()->addMinutes($config->fetch_interval_minutes)]);
    }
}
