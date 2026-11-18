<?php

namespace App\Services;

use App\Enums\PriceListImportStatus;
use App\Jobs\DownloadExternalPricePage;
use App\Jobs\PreparePriceListImport;
use App\Jobs\SynchronizeSupplier;
use App\Models\ExternalDownload;
use App\Models\ExternalImportQueueItem;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSyncAttempt;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use App\Models\SupplierVersionClaim;
use App\Services\PriceList\ActivationDispatcher;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExternalImportQueue
{
    /** @var array<int, int> */
    private array $executing = [];

    public function enqueue(SupplierSyncRun $run, ?int $requester = null, ?string $group = null, ?int $executionGeneration = null): ExternalImportQueueItem
    {
        $lane = $requester === null ? 'system' : 'user:'.$requester;
        DB::table('external_import_queue_lanes')->insertOrIgnore(['id' => $lane]);

        return DB::transaction(function () use ($run, $requester, $group, $executionGeneration, $lane): ExternalImportQueueItem {
            DB::table('external_import_queue_lanes')->where('id', $lane)->lockForUpdate()->first();
            $executionGeneration ??= $this->generation($run->id);
            $item = ExternalImportQueueItem::firstOrCreate(['lane_id' => $lane, 'supplier_sync_run_id' => $run->id, 'generation' => $executionGeneration], [
                'requested_by' => $requester, 'group_id' => $group ?? (string) Str::uuid(), 'enqueued_at' => now(), 'dispatch_requested_at' => now(),
            ]);
            SupplierSyncRun::whereKey($run->id)->whereNull('execution_queue_item_id')->update(['execution_queue_item_id' => $item->id]);

            return $item;
        }, 3);
    }

    public function generation(int $runId): int
    {
        $run = SupplierSyncRun::find($runId);

        return (int) (ExternalImportQueueItem::find($run?->execution_queue_item_id)?->generation ?? 1);
    }

    public function runForImport(PriceListImport $import): ?SupplierSyncRun
    {
        if (! in_array($import->source_type->value, ['icorp', 'pricelist_tj'], true)) {
            return null;
        }

        $attempt = SupplierSyncAttempt::where('price_list_import_id', $import->id)->first();
        if (! $attempt && isset($import->source_metadata['download_id'])) {
            $download = ExternalDownload::find($import->source_metadata['download_id']);
            $attempt = $download ? SupplierSyncAttempt::find($download->supplier_sync_attempt_id) : null;
        }

        return $attempt?->run;
    }

    public function importGeneration(PriceListImport $import): int
    {
        $run = $this->runForImport($import);

        return $run ? $this->generation($run->id) : 1;
    }

    public function importRetryDeadline(PriceListImport $import): \DateTimeInterface
    {
        $run = $this->runForImport($import);

        return $run ? (ExternalImportQueueItem::find($run->execution_queue_item_id)?->recovery_deadline ?? now()->addHours(2)) : now()->addHours(2);
    }

    public function executeImport(PriceListImport $import, int $generation, Closure $work): mixed
    {
        $run = $this->runForImport($import);
        if (! $run && in_array($import->source_type->value, ['icorp', 'pricelist_tj'], true)) {
            $run = $this->adoptImport($import);
        }
        if (! $run) {
            return $work();
        }

        return $this->execute($run, $generation, $work, fn (ExternalImportQueueItem $item) => $item->update(['state' => in_array($import->status, [PriceListImportStatus::Preview, PriceListImportStatus::Committing], true) ? 'publishing' : 'importing']));
    }

    public function adoptImport(PriceListImport $import): SupplierSyncRun
    {
        return DB::transaction(function () use ($import): SupplierSyncRun {
            DB::table('external_import_queue_lanes')->where('id', 'system')->lockForUpdate()->first();
            Organization::whereKey($import->supplier_organization_id)->lockForUpdate()->firstOrFail();
            if ($run = $this->runForImport($import)) {
                return $run;
            }
            $configuration = SupplierSynchronization::where('supplier_organization_id', $import->supplier_organization_id)->first();
            $run = SupplierSyncRun::create(['supplier_organization_id' => $import->supplier_organization_id, 'trigger' => 'legacy',
                'status' => 'processing', 'configuration_snapshot' => $configuration?->toArray() ?? ['version' => 0, 'selected_source' => $import->source_type->value]]);
            $run->attempts()->create(['source' => $import->source_type->value, 'status' => 'processing', 'price_list_import_id' => $import->id]);
            $this->enqueue($run);

            return $run->fresh();
        });
    }

    public function retry(SupplierSyncRun $run, ?int $requester = null): ExternalImportQueueItem
    {
        return DB::transaction(function () use ($run, $requester): ExternalImportQueueItem {
            $run = $run->fresh();
            $original = ExternalImportQueueItem::find($run->execution_queue_item_id);
            if ($original) {
                DB::table('external_import_queue_lanes')->where('id', $original->lane_id)->lockForUpdate()->first();
                $this->refresh($original);
                $original->refresh();
                abort_unless($original->finished_at !== null, 422);
            }
            $item = $this->enqueue($run, $requester, executionGeneration: ($original?->generation ?? 0) + 1);
            $item->update(['generation' => $item->generation, 'state' => 'queued', 'wait_reason' => null,
                'finished_at' => null, 'started_at' => null, 'recovery_deadline' => null, 'next_retry_at' => null,
                'heartbeat_at' => null, 'worker_token' => null, 'worker_expires_at' => null, 'dispatch_requested_at' => now(), 'revision' => $item->revision + 1]);
            $run->update(['execution_queue_item_id' => $item->id, 'active_supplier_id' => $run->supplier_organization_id,
                'status' => 'queued', 'finished_at' => null, 'next_retry_at' => null, 'owner_token' => null, 'lease_expires_at' => null]);
            DB::afterCommit(fn () => $this->dispatchLane($item->lane_id));

            return $item;
        });
    }

    /** The lane row is held through each bounded stage, fencing replacement workers and retries. */
    public function execute(SupplierSyncRun $run, int $generation, Closure $work, ?Closure $claimStage = null): mixed
    {
        $run = $run->fresh();
        $item = $run->execution_queue_item_id ? ExternalImportQueueItem::findOrFail($run->execution_queue_item_id) : $this->enqueue($run);
        if (($this->executing[$item->id] ?? null) === $generation) {
            return $work();
        }
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($item, $generation, $token, $claimStage): bool {
            $lane = DB::table('external_import_queue_lanes')->where('id', $item->lane_id)->lockForUpdate()->first();
            $item = $item->fresh();
            $head = ExternalImportQueueItem::where('lane_id', $item->lane_id)->whereNull('finished_at')->orderBy('id')->value('id');
            if ($item->finished_at || $item->generation !== $generation || (int) $head !== $item->id
                || ($lane->owner_item_id !== null && (int) $lane->owner_item_id !== $item->id)
                || ($item->worker_token && $item->worker_expires_at?->isFuture())) {
                return false;
            }
            DB::table('external_import_queue_lanes')->where('id', $item->lane_id)->update(['owner_item_id' => $item->id]);
            $item->update(['started_at' => $item->started_at ?? now(),
                'recovery_deadline' => $item->recovery_deadline ?? now()->addHours((int) config('external-prices.lifecycle.recovery_hours', 24)),
                'heartbeat_at' => now(), 'worker_token' => $token, 'worker_expires_at' => now()->addSeconds(140), 'revision' => $item->revision + 1]);
            if ($claimStage && ! $item->recovery_deadline->isPast()) {
                $claimStage($item);
            }

            return true;
        });
        if (! $claimed) {
            return null;
        }
        $this->executing[$item->id] = $generation;
        try {
            return DB::transaction(function () use ($item, $generation, $token, $work): mixed {
                DB::table('external_import_queue_lanes')->where('id', $item->lane_id)->lockForUpdate()->first();
                $current = $item->fresh();
                if ($current->worker_token !== $token || $current->generation !== $generation || ! $current->worker_expires_at?->isFuture()) {
                    return null;
                }
                if ($current->recovery_deadline->isPast()) {
                    $this->expire($current);

                    return null;
                }
                if ($current->state === 'retry' && $current->next_retry_at?->isFuture()) {
                    return null;
                }
                $result = $work();
                if (! $current->worker_expires_at->isFuture()) {
                    throw new ExternalServiceUnavailable('lease_lost');
                }

                $current->update(['worker_token' => null, 'worker_expires_at' => null]);
                $this->refresh($current);
                unset($this->executing[$item->id]);

                return $result;
            });
        } finally {
            unset($this->executing[$item->id]);
            DB::transaction(function () use ($item, $token): void {
                DB::table('external_import_queue_lanes')->where('id', $item->lane_id)->lockForUpdate()->first();
                $current = $item->fresh();
                if ($current->worker_token === $token) {
                    $current->update(['worker_token' => null, 'worker_expires_at' => null]);
                    $this->refresh($current);
                }
            });
        }
    }

    private function expire(ExternalImportQueueItem $item): void
    {
        $run = $item->run;
        Organization::whereKey($run->supplier_organization_id)->lockForUpdate()->firstOrFail();
        $importIds = $run->attempts()->whereNotNull('price_list_import_id')->pluck('price_list_import_id');
        PriceListImport::whereIn('id', $importIds)->whereNotIn('status', [PriceListImportStatus::Completed, PriceListImportStatus::Superseded])
            ->update(['status' => PriceListImportStatus::Failed, 'failure_stage' => 'recovery_deadline', 'failure_message' => 'recovery_deadline', 'failed_at' => now()]);
        ExternalDownload::whereIn('supplier_sync_attempt_id', $run->attempts()->pluck('id'))->whereIn('status', ['pending', 'downloading', 'retry', 'cooldown', 'paused'])
            ->update(['status' => 'failed', 'reason' => 'recovery_deadline', 'worker_token' => null, 'worker_expires_at' => null]);
        SupplierVersionClaim::where('supplier_organization_id', $run->supplier_organization_id)->where('state', '!=', 'completed')
            ->where(fn ($query) => $query->whereIn('price_list_import_id', $importIds)->orWhereIn('external_download_id', ExternalDownload::whereIn('supplier_sync_attempt_id', $run->attempts()->pluck('id'))->select('id')))
            ->update(['state' => 'failed']);
        $run->update(['status' => 'failed', 'reason' => 'recovery_deadline', 'active_supplier_id' => null, 'finished_at' => now()]);
    }

    public function refresh(ExternalImportQueueItem $item): void
    {
        $run = $item->run()->with('attempts.import')->firstOrFail();
        $attempt = $run->attempts->sortByDesc('id')->first(fn ($attempt): bool => $attempt->price_list_import_id !== null)
            ?? $run->attempts->sortByDesc('id')->first();
        $import = $attempt?->import;
        $download = ExternalDownload::whereIn('supplier_sync_attempt_id', $run->attempts->modelKeys())->latest('id')->first();
        $reviewReason = $import?->status === PriceListImportStatus::Preview ? app(SupplierSourcePolicy::class)->reason($import) : null;
        $mayFallBack = ! app(SupplierVersions::class)->enabled() && count(array_filter($run->configuration_snapshot['sources'] ?? [], fn ($source): bool => (bool) ($source['enabled'] ?? false))) > 1 && ! $run->finished_at;
        $blockedAttempt = $run->attempts->first(fn ($attempt): bool => in_array($attempt->reason, ['authentication', 'permission', 'challenge', 'takeover_required'], true));
        $state = match (true) {
            in_array($import?->status, [PriceListImportStatus::Completed, PriceListImportStatus::Superseded], true) => 'completed',
            $import?->status === PriceListImportStatus::Failed && ! $mayFallBack => 'failed',
            $download?->status === 'paused', $run->status === 'review', $run->finished_at !== null && $blockedAttempt !== null && $run->status !== 'completed' => 'blocked',
            $download?->status === 'failed' && ! $mayFallBack, $run->status === 'failed' => 'failed',
            $run->finished_at !== null => $run->status === 'completed' ? 'completed' : 'skipped',
            $import?->status === PriceListImportStatus::Committing => 'publishing',
            $reviewReason === 'validation_failed' && ! $mayFallBack => 'failed',
            $reviewReason !== null && ! $mayFallBack => in_array($reviewReason, ['configuration_changed', 'mapping_changed', 'profile_changed', 'already_imported_day', 'external_fetch_disabled', 'source_disabled', 'source_not_selected'], true) ? 'skipped' : 'blocked',
            $reviewReason !== null && (str_starts_with($reviewReason, 'review_') || $reviewReason === 'preferred_unresolved') => 'blocked',
            $import !== null => 'importing',
            in_array($download?->status, ['retry', 'cooldown'], true) => 'retry',
            $download?->status === 'downloading' && $download->worker_token !== null => 'downloading',
            default => 'queued',
        };
        $terminal = in_array($state, ['completed', 'failed', 'skipped', 'blocked'], true);
        $item->update(['state' => $state, 'external_download_id' => $download?->id, 'price_list_import_id' => $import?->id,
            'wait_reason' => $reviewReason ?? $download?->reason ?? $blockedAttempt?->reason ?? $run->reason, 'next_retry_at' => $download?->next_retry_at ?? $run->next_retry_at,
            'heartbeat_at' => now(), 'revision' => $item->revision + 1, 'finished_at' => $terminal ? ($item->finished_at ?? now()) : null]);
        if ($terminal && (int) $run->execution_queue_item_id === $item->id) {
            $run->update(['active_supplier_id' => null, 'finished_at' => $run->finished_at ?? now(),
                'status' => ['blocked' => 'review', 'skipped' => 'unchanged'][$state] ?? $state, 'reason' => $reviewReason ?? $blockedAttempt?->reason ?? $run->reason]);
        }
        if ($terminal) {
            DB::table('external_import_queue_lanes')->where('id', $item->lane_id)->where('owner_item_id', $item->id)->update(['owner_item_id' => null]);
            DB::afterCommit(fn () => $this->dispatchLane($item->lane_id));
        }
    }

    public function dispatchLane(string $lane): void
    {
        if (app(SupplierVersions::class)->paused()) {
            return;
        }
        $item = ExternalImportQueueItem::where('lane_id', $lane)->whereNull('finished_at')->orderBy('id')->first();
        if (! $item) {
            return;
        }
        if ((int) $item->run->execution_queue_item_id !== $item->id) {
            $execution = ExternalImportQueueItem::where('supplier_sync_run_id', $item->supplier_sync_run_id)->where('generation', $item->generation)->orderBy('id')->firstOrFail();
            if ($execution->finished_at) {
                DB::transaction(function () use ($item, $execution): void {
                    DB::table('external_import_queue_lanes')->where('id', $item->lane_id)->lockForUpdate()->first();
                    $item->update(array_replace($execution->only(['state', 'wait_reason', 'external_download_id', 'price_list_import_id']),
                        ['finished_at' => now(), 'heartbeat_at' => now(), 'next_retry_at' => null, 'revision' => $item->revision + 1]));
                    DB::table('external_import_queue_lanes')->where('id', $item->lane_id)->where('owner_item_id', $item->id)->update(['owner_item_id' => null]);
                    DB::afterCommit(fn () => $this->dispatchLane($item->lane_id));
                });
            } else {
                DB::transaction(function () use ($item, $execution): void {
                    DB::table('external_import_queue_lanes')->where('id', $item->lane_id)->lockForUpdate()->first();
                    $head = ExternalImportQueueItem::where('lane_id', $item->lane_id)->whereNull('finished_at')->orderBy('id')->value('id');
                    if ((int) $head !== $item->id) {
                        return;
                    }
                    DB::table('external_import_queue_lanes')->where('id', $item->lane_id)->update(['owner_item_id' => $item->id]);
                    $item->update(['state' => 'queued', 'started_at' => $item->started_at ?? now(), 'wait_reason' => 'shared_execution',
                        'external_download_id' => $execution->external_download_id, 'price_list_import_id' => $execution->price_list_import_id,
                        'heartbeat_at' => $execution->heartbeat_at, 'revision' => $item->revision + 1]);
                });
            }

            return;
        }
        if ($item->next_retry_at?->isFuture() && ! $item->recovery_deadline?->isPast()) {
            return;
        }
        $import = $item->import ?? $item->run->attempts()->whereNotNull('price_list_import_id')->with('import')->latest('id')->first()?->import;
        if ($import && in_array($import->status, [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Committing], true) && ! $item->recovery_deadline?->isPast()) {
            if ($item->worker_token && $item->worker_expires_at?->isFuture()) {
                return;
            }
            if ($item->heartbeat_at && $item->heartbeat_at->gt(now()->subSeconds(140))) {
                return;
            }
            $item->update(['dispatch_requested_at' => now()]);
            if ($import->status === PriceListImportStatus::Committing) {
                app(ActivationDispatcher::class)->dispatch($import);
            } else {
                PreparePriceListImport::dispatch($import)->onQueue(config('price-list-imports.queue'))->afterCommit();
            }
            $item->update(['dispatched_at' => now()]);

            return;
        }
        if ($import?->status === PriceListImportStatus::Preview && app(SupplierSourcePolicy::class)->reason($import) === null && ! $item->recovery_deadline?->isPast()) {
            app(ActivationDispatcher::class)->dispatch($import);

            return;
        }
        $item->update(['dispatch_requested_at' => now()]);
        SynchronizeSupplier::dispatch($item->supplier_sync_run_id, $item->generation)->onQueue(config('price-list-imports.queue'))->afterCommit();
        $download = $item->download;
        if ($download && in_array($download->status, ['pending', 'downloading', 'retry'], true)) {
            DownloadExternalPricePage::dispatch($download->id, $item->generation)->onQueue(config('price-list-imports.queue'))->afterCommit();
        }
        $item->update(['dispatched_at' => now()]);
    }

    public function reconcile(): void
    {
        SupplierSyncRun::whereNotNull('active_supplier_id')->where('trigger', '!=', 'mailbox')->whereNull('execution_queue_item_id')
            ->where(function ($query): void {
                $query->whereIn('configuration_snapshot->selected_source', ['icorp', 'pricelist_tj'])
                    ->orWhere('configuration_snapshot->sources->icorp->enabled', true)->orWhere('configuration_snapshot->sources->pricelist_tj->enabled', true)
                    ->orWhereHas('attempts', fn ($query) => $query->whereIn('source', ['icorp', 'pricelist_tj']));
            })
            ->eachById(fn (SupplierSyncRun $run) => $this->enqueue($run));
        PriceListImport::whereIn('source_type', ['icorp', 'pricelist_tj'])->whereIn('status', [PriceListImportStatus::Pending, PriceListImportStatus::Processing, PriceListImportStatus::Preview, PriceListImportStatus::Committing])
            ->whereDoesntHave('syncAttempts')->eachById(fn (PriceListImport $import) => $this->adoptImport($import));
        foreach (ExternalImportQueueItem::whereNull('finished_at')->distinct()->pluck('lane_id') as $lane) {
            $this->dispatchLane($lane);
        }
    }
}
