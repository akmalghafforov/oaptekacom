<?php

namespace Tests\Feature;

use App\Enums\PriceListImportStatus;
use App\Jobs\DownloadExternalPricePage;
use App\Jobs\NotifyExternalServiceIncident;
use App\Jobs\PreparePriceListImport;
use App\Jobs\SynchronizeSupplier;
use App\Models\ExternalDownload;
use App\Models\ExternalImportQueueItem;
use App\Models\ExternalServiceState;
use App\Models\ExternalSupplierPartner;
use App\Models\Organization;
use App\Models\PriceListImport;
use App\Models\SupplierSyncAttempt;
use App\Models\SupplierSynchronization;
use App\Models\SupplierSyncRun;
use App\Models\User;
use App\Services\ExternalImportQueue;
use App\Services\ExternalPriceDownload;
use App\Services\ExternalPriceWorkbook;
use App\Services\ExternalServiceControl;
use App\Services\ExternalServiceUnavailable;
use App\Services\ImportProgress;
use App\Services\PriceListTjClient;
use App\Services\SupplierSynchronizationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ExternalImportQueueTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_three_suppliers_keep_the_lane_until_publication_finishes(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        $user = User::factory()->create();
        $queue = app(ExternalImportQueue::class);
        $runs = collect(range(1, 3))->map(fn () => $this->queuedRun());
        $items = $runs->map(fn ($run) => $queue->enqueue($run, $user->id));
        $steps = [];
        foreach ($runs as $index => $run) {
            foreach (['downloading', 'importing', 'publishing'] as $stage) {
                $queue->execute($run, 1, function () use (&$steps, $stage, $index): void {
                    $steps[] = [$index, $stage];
                });
                foreach ($runs->slice($index + 1) as $waiting) {
                    $queue->execute($waiting, 1, function (): void {
                        $this->fail('A later supplier ran before cutover.');
                    });
                }
                $this->assertNull($items[$index]->fresh()->finished_at);
            }
            $queue->execute($run, 1, fn () => $run->update(['status' => 'completed', 'active_supplier_id' => null, 'finished_at' => now()]));
            $this->assertSame('completed', $items[$index]->fresh()->state);
        }
        $this->assertSame([[0, 'downloading'], [0, 'importing'], [0, 'publishing'], [1, 'downloading'], [1, 'importing'], [1, 'publishing'], [2, 'downloading'], [2, 'importing'], [2, 'publishing']], $steps);
        Queue::assertPushed(SynchronizeSupplier::class);
    }

    public function test_other_users_and_the_system_have_independent_lanes(): void
    {
        $queue = app(ExternalImportQueue::class);
        $users = User::factory()->count(2)->create();
        $runs = collect(range(1, 3))->map(fn () => $this->queuedRun());
        $items = $runs->map(fn ($run, $i) => $queue->enqueue($run, $users[$i]->id ?? null));
        foreach ($runs as $run) {
            $queue->execute($run, 1, fn () => $run->update(['reason' => 'executed']));
        }
        $this->assertSame(3, $runs->filter(fn ($run) => $run->fresh()->reason === 'executed')->count());
        $this->assertSame(['user:'.$users[0]->id, 'user:'.$users[1]->id, 'system'], $items->pluck('lane_id')->all());
    }

    public function test_duplicate_requests_reference_one_execution_only_when_their_lane_reaches_them(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        $queue = app(ExternalImportQueue::class);
        $users = User::factory()->count(2)->create();
        $earlier = $this->queuedRun();
        $run = $this->queuedRun();
        $owner = $queue->enqueue($run, $users[0]->id);
        $queue->enqueue($earlier, $users[1]->id);
        $reference = $queue->enqueue($run, $users[1]->id);
        $this->assertSame($owner->id, $queue->enqueue($run, $users[0]->id)->id);
        $queue->execute($run, 1, fn () => $run->update(['status' => 'completed', 'active_supplier_id' => null, 'finished_at' => now()]));
        $this->assertNull($reference->fresh()->finished_at);
        $queue->execute($earlier, 1, fn () => $earlier->update(['status' => 'unchanged', 'active_supplier_id' => null, 'finished_at' => now()]));
        $this->assertSame('completed', $reference->fresh()->state);
        $this->assertSame($owner->id, $run->fresh()->execution_queue_item_id);
        Queue::assertNotPushed(SynchronizeSupplier::class);
    }

    public function test_retry_wait_retains_lane_and_queued_time_does_not_consume_recovery_deadline(): void
    {
        $this->freezeTime();
        $queue = app(ExternalImportQueue::class);
        $run = $this->queuedRun();
        $waiting = $this->queuedRun();
        $item = $queue->enqueue($run);
        $second = $queue->enqueue($waiting);
        $this->travel(2)->days();
        $queue->execute($run, 1, fn () => $run->update(['status' => 'retry', 'next_retry_at' => now()->addHour()]));
        $this->assertTrue($item->fresh()->recovery_deadline->equalTo(now()->addDay()->startOfSecond()));
        $this->assertNull($second->fresh()->recovery_deadline);
        $queue->execute($waiting, 1, function (): void {
            $this->fail('A retry wait released the lane.');
        });
        $this->assertNull($second->fresh()->started_at);
    }

    public function test_deadline_fails_work_and_advances_without_extending_the_deadline(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        $this->freezeTime();
        $queue = app(ExternalImportQueue::class);
        $run = $this->queuedRun();
        $next = $this->queuedRun();
        $item = $queue->enqueue($run);
        $queue->enqueue($next);
        $queue->execute($run, 1, fn () => $run->update(['reason' => 'started']));
        $deadline = $item->fresh()->recovery_deadline;
        $this->travel(25)->hours();
        $queue->execute($run, 1, function (): void {
            $this->fail('Expired work ran.');
        });
        $this->assertSame('failed', $item->fresh()->state);
        $this->assertTrue($deadline->equalTo($item->fresh()->recovery_deadline));
        $queue->execute($next, 1, fn () => $next->update(['reason' => 'started']));
        $this->assertSame('started', $next->fresh()->reason);
        Queue::assertPushed(SynchronizeSupplier::class);
    }

    public function test_manual_retry_fences_old_generation_and_rolls_back_expired_worker_writes(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        $this->freezeTime();
        $queue = app(ExternalImportQueue::class);
        $run = $this->queuedRun();
        $item = $queue->enqueue($run);
        $queue->execute($run, 1, fn () => $run->update(['status' => 'failed', 'active_supplier_id' => null, 'finished_at' => now()]));
        $retried = $queue->retry($run);
        $queue->execute($run, 1, function (): void {
            $this->fail('An obsolete generation ran.');
        });
        $this->assertSame(2, $retried->generation);
        $this->assertNotSame($item->id, $retried->id);
        $this->assertSame('failed', $item->fresh()->state);
        try {
            $queue->execute($run, 2, function () use ($run): void {
                $run->update(['reason' => 'stale-write']);
                $this->travel(141)->seconds();
            });
            $this->fail('Expired stage committed its changes.');
        } catch (ExternalServiceUnavailable $exception) {
            $this->assertSame('lease_lost', $exception->reason);
        }
        $this->assertNotSame('stale-write', $run->fresh()->reason);
        $queue->execute($run, 2, fn () => $run->update(['reason' => 'replacement']));
        $this->assertSame('replacement', $run->fresh()->reason);
        Queue::assertPushed(SynchronizeSupplier::class);
    }

    public function test_queue_identity_and_group_totals_survive_import_handoff_and_pagination_without_dispatch(): void
    {
        $user = User::factory()->admin()->create();
        $queue = app(ExternalImportQueue::class);
        $run = $this->queuedRun();
        $group = (string) Str::uuid();
        $item = $queue->enqueue($run, $user->id, $group);
        $second = $queue->enqueue($this->queuedRun(), $user->id, $group);
        $this->actingAs($user);
        $before = app(ImportProgress::class)->run($run->load(['supplier', 'attempts']));
        $this->assertSame('В очереди', $before['label']);
        $import = PriceListImport::factory()->for($run->supplier, 'supplier')->create(['source_type' => 'icorp', 'status' => PriceListImportStatus::Processing]);
        SupplierSyncAttempt::factory()->for($run, 'run')->for($import, 'import')->create(['source' => 'icorp', 'status' => 'processing']);
        $queue->execute($run, 1, fn () => null);
        $after = app(ImportProgress::class)->import($import->load('supplier')->loadCount('rows'));
        $this->assertSame($before['id'], $after['id']);
        $this->assertSame('Импорт', $after['label']);
        Queue::fake();
        $this->getJson(route('price-list-imports.index', ['tab' => 'services', 'page' => 2]))
            ->assertJsonPath('queue.counts.waiting', 1)->assertJsonPath('queue.counts.processing', 1)
            ->assertJsonPath('queue.groups.0.total', 2)->assertJsonPath('queue.groups.0.finished', 0)
            ->assertJsonPath('queue.active.0.id', 'queue-'.$item->id);
        $this->get(route('price-list-imports.index', ['tab' => 'services', 'page' => 2]))->assertSee('Текущий поставщик')->assertSee($run->supplier->name);
        $this->assertNull($second->fresh()->started_at);
        Queue::assertNothingPushed();
    }

    public function test_manual_and_email_jobs_do_not_join_external_lanes(): void
    {
        $queue = app(ExternalImportQueue::class);
        $queue->enqueue($this->queuedRun());
        foreach (['manual', 'email'] as $source) {
            $import = PriceListImport::factory()->create(['source_type' => $source]);
            $queue->executeImport($import, 1, fn () => $import->update(['failure_message' => 'independent']));
            $this->assertSame('independent', $import->fresh()->failure_message);
        }
        $this->assertSame(1, ExternalImportQueueItem::count());
    }

    #[TestWith(['120', 3600])]
    #[TestWith(['7200', 7200])]
    #[TestWith(['invalid header', 3600])]
    #[TestWith(['', 3600])]
    #[TestWith(['Wed, 18 Nov 2026 15:00:00 GMT', 10800])]
    public function test_upstream_retry_after_applies_shared_cooldown(string $header, int $seconds): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 12:00:00 UTC'));
        Queue::fake([NotifyExternalServiceIncident::class]);
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['upstream.test/*' => Http::response([], 429, ['Retry-After' => $header])]);
        try {
            app(ExternalServiceControl::class)->request('icorp', fn () => Http::get('https://upstream.test/prices'));
            $this->fail('A rate limit must stop this attempt.');
        } catch (ExternalServiceUnavailable $exception) {
            $this->assertSame('rate_limited', $exception->reason);
        }
        $this->assertTrue(ExternalServiceState::findOrFail('icorp')->cooldown_until->equalTo(now()->addSeconds($seconds)));
        Http::assertSentCount(1);
        Queue::assertPushed(NotifyExternalServiceIncident::class);
    }

    public function test_polling_rate_limit_is_per_user_and_returns_retry_after(): void
    {
        config(['external-prices.lifecycle.poll_per_minute' => 2]);
        $user = User::factory()->admin()->create();
        $this->actingAs($user);
        $url = route('price-list-imports.index', ['tab' => 'services']);
        $this->getJson($url)->assertOk();
        $this->getJson($url)->assertOk();
        $this->getJson($url)->assertTooManyRequests()->assertHeader('Retry-After');
        $this->actingAs(User::factory()->admin()->create())->getJson($url)->assertOk();
    }

    public function test_reconciliation_recovers_persisted_work_when_initial_dispatch_was_lost(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        $run = $this->queuedRun();
        $queue = app(ExternalImportQueue::class);
        $item = $queue->enqueue($run);
        $this->assertNull($item->dispatched_at);
        $queue->reconcile();
        $this->assertNotNull($item->fresh()->dispatched_at);
        Queue::assertPushed(SynchronizeSupplier::class, fn ($job) => $job->runId === $run->id && $job->generation === 1);
    }

    public function test_real_download_and_import_jobs_publish_three_suppliers_in_user_order(): void
    {
        Queue::fake([SynchronizeSupplier::class, DownloadExternalPricePage::class, PreparePriceListImport::class]);
        Storage::fake('local');
        $this->freezeTime();
        $user = User::factory()->admin()->create();
        $sources = SupplierSynchronization::defaultSources();
        $sources['email']['enabled'] = false;
        $sources['icorp']['enabled'] = false;
        $runs = collect(range(1, 3))->map(function ($index) use ($sources, $user): SupplierSyncRun {
            $configuration = SupplierSynchronization::factory()->create(['sources' => $sources, 'settings_confirmed' => true, 'automatic_enabled' => true]);
            ExternalSupplierPartner::factory()->create(['supplier_organization_id' => $configuration->supplier_organization_id,
                'source' => 'pricelist_tj', 'external_id' => 'queue-supplier-'.$index, 'profile_active' => true,
                'profile_configuration' => ExternalPriceWorkbook::profile()]);

            return app(SupplierSynchronizationService::class)->request($configuration->supplier, requesterId: $user->id);
        });
        $this->mock(PriceListTjClient::class)->shouldReceive('pricePage')->times(3)->andReturnUsing(fn (string $id): array => [
            'company' => ['id' => $id, 'published_at' => now()->subHour()->toIso8601String()],
            'items' => [['external_id' => 'one', 'name' => 'Аспирин', 'price' => '10.50', 'quantity' => '5']],
            'total' => 1, 'total_pages' => 1, 'account_discount' => null,
        ]);
        foreach ($runs as $index => $run) {
            app(SupplierSynchronizationService::class)->process($run);
            $download = ExternalDownload::latest('id')->firstOrFail();
            app(ExternalPriceDownload::class)->process($download->id);
            $import = PriceListImport::latest('id')->firstOrFail();
            $this->assertSame(PriceListImportStatus::Pending, $import->status);
            foreach ($runs->slice($index + 1) as $waiting) {
                app(SupplierSynchronizationService::class)->process($waiting);
                $this->assertSame(0, $waiting->attempts()->count());
            }
            $this->assertSame($index + 1, ExternalDownload::count());
            app()->call([new PreparePriceListImport($import), 'handle']);
            $this->assertSame(PriceListImportStatus::Completed, $import->fresh()->status);
            $this->assertSame($import->id, $run->supplier->fresh()->active_price_list_import_id);
            $this->assertSame('completed', ExternalImportQueueItem::where('supplier_sync_run_id', $run->id)->value('state'));
        }
        $this->assertSame(3, DB::table('audit_events')->where('event', 'price_list_import.activated')->count());
        Queue::assertPushed(PreparePriceListImport::class, 3);
    }

    public function test_manual_retry_joins_the_tail_and_recovery_dispatches_its_import_when_it_reaches_the_head(): void
    {
        Queue::fake([SynchronizeSupplier::class, PreparePriceListImport::class]);
        $queue = app(ExternalImportQueue::class);
        $user = User::factory()->create();
        $failed = $this->queuedRun();
        $next = $this->queuedRun();
        $original = $queue->enqueue($failed, $user->id);
        $queue->enqueue($next, $user->id);
        $import = PriceListImport::factory()->for($failed->supplier, 'supplier')->create(['source_type' => 'icorp', 'status' => PriceListImportStatus::Failed]);
        SupplierSyncAttempt::factory()->for($failed, 'run')->for($import, 'import')->create(['source' => 'icorp', 'status' => 'failed']);
        $queue->execute($failed, 1, fn () => null);
        $retry = $queue->retry($failed, $user->id);
        $import->update(['status' => PriceListImportStatus::Pending]);
        $queue->execute($failed, 2, function (): void {
            $this->fail('A manual retry jumped ahead of existing queued work.');
        });
        $this->assertNull($retry->fresh()->started_at);
        $this->assertNotNull($original->fresh()->finished_at);
        $queue->execute($next, 1, fn () => $next->update(['status' => 'completed', 'active_supplier_id' => null, 'finished_at' => now()]));
        Queue::assertPushed(PreparePriceListImport::class, fn ($job) => $job->import->id === $import->id && $job->generation === 2);
    }

    public function test_recovery_never_adopts_email_only_runs_into_external_queue(): void
    {
        Queue::fake();
        $run = $this->queuedRun();
        $run->update(['configuration_snapshot' => ['version' => 1, 'selected_source' => 'email', 'sources' => ['email' => ['enabled' => true], 'icorp' => ['enabled' => false], 'pricelist_tj' => ['enabled' => false]]]]);
        app(ExternalImportQueue::class)->reconcile();
        $this->assertSame(0, ExternalImportQueueItem::count());
        Queue::assertNothingPushed();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_retry_endpoint_resets_only_the_new_execution_and_respects_service_cooldown(bool $cooldown): void
    {
        Queue::fake([SynchronizeSupplier::class, PreparePriceListImport::class]);
        $user = User::factory()->admin()->create(['two_factor_confirmed_at' => now()]);
        $run = $this->queuedRun();
        $sources = SupplierSynchronization::defaultSources();
        $sources['email']['enabled'] = false;
        $sources['pricelist_tj']['enabled'] = false;
        $sources['icorp']['enabled'] = true;
        $configuration = SupplierSynchronization::factory()->for($run->supplier, 'supplier')->create(['settings_confirmed' => true, 'automatic_enabled' => true, 'sources' => $sources, 'version' => 1]);
        $partner = ExternalSupplierPartner::factory()->create(['source' => 'icorp', 'supplier_organization_id' => $run->supplier_organization_id, 'profile_active' => true, 'profile_version' => 1]);
        $import = PriceListImport::factory()->for($run->supplier, 'supplier')->create(['status' => PriceListImportStatus::Failed, 'source_type' => 'icorp',
            'failure_stage' => 'parsing', 'total_rows' => 10, 'valid_rows' => 7, 'error_rows' => 3,
            'source_metadata' => ['configuration_version' => $configuration->version, 'partner_id' => $partner->id, 'external_id' => $partner->external_id, 'profile_version' => $partner->profile_version]]);
        SupplierSyncAttempt::factory()->for($run, 'run')->for($import, 'import')->create(['source' => 'icorp', 'status' => 'failed']);
        $queue = app(ExternalImportQueue::class);
        $original = $queue->enqueue($run, $user->id);
        $queue->execute($run, 1, fn () => null);
        if ($cooldown) {
            ExternalServiceState::whereKey('icorp')->update(['status' => 'cooldown', 'cooldown_until' => now()->addHour()]);
        }
        $this->actingAs($user)->post(route('price-list-imports.retry', $import))->assertRedirect();
        if ($cooldown) {
            $this->assertSame(PriceListImportStatus::Failed, $import->fresh()->status);
            $this->assertSame(1, ExternalImportQueueItem::where('supplier_sync_run_id', $run->id)->count());
            Queue::assertNotPushed(PreparePriceListImport::class);
        } else {
            $this->assertSame(PriceListImportStatus::Pending, $import->fresh()->status);
            $this->assertSame([0, 0, 0], [$import->fresh()->total_rows, $import->fresh()->valid_rows, $import->fresh()->error_rows]);
            $this->assertSame(2, $queue->generation($run->id));
            $this->assertSame('failed', $original->fresh()->state);
            Queue::assertPushed(PreparePriceListImport::class, fn ($job) => $job->generation === 2);
        }
    }

    public function test_queued_duplicate_retains_its_generation_outcome_after_another_user_retries(): void
    {
        Queue::fake([SynchronizeSupplier::class]);
        $queue = app(ExternalImportQueue::class);
        $users = User::factory()->count(2)->create();
        $run = $this->queuedRun();
        $earlier = $this->queuedRun();
        $queue->enqueue($run, $users[0]->id);
        $queue->enqueue($earlier, $users[1]->id);
        $reference = $queue->enqueue($run, $users[1]->id);
        $queue->execute($run, 1, fn () => $run->update(['status' => 'failed', 'active_supplier_id' => null, 'finished_at' => now()]));
        $retry = $queue->retry($run, $users[0]->id);
        $queue->execute($earlier, 1, fn () => $earlier->update(['status' => 'completed', 'active_supplier_id' => null, 'finished_at' => now()]));
        $this->assertSame('failed', $reference->fresh()->state);
        $this->assertNotNull($reference->fresh()->finished_at);
        $this->assertSame(1, $reference->generation);
        $this->assertNull($retry->fresh()->finished_at);
        Queue::assertPushed(SynchronizeSupplier::class);
    }

    public function test_duplicate_at_lane_head_owns_its_place_while_waiting_for_shared_work(): void
    {
        $queue = app(ExternalImportQueue::class);
        $users = User::factory()->count(2)->create();
        $run = $this->queuedRun();
        $next = $this->queuedRun();
        $queue->enqueue($run, $users[0]->id);
        $reference = $queue->enqueue($run, $users[1]->id);
        $queue->enqueue($next, $users[1]->id);
        $queue->dispatchLane($reference->lane_id);
        $this->assertSame($reference->id, (int) DB::table('external_import_queue_lanes')->where('id', $reference->lane_id)->value('owner_item_id'));
        $this->assertSame('shared_execution', $reference->fresh()->wait_reason);
        $queue->execute($next, 1, function (): void {
            $this->fail('A later supplier overtook a shared execution.');
        });
        $this->assertNull($next->fresh()->reason);
    }

    private function queuedRun(): SupplierSyncRun
    {
        $supplier = Organization::factory()->wholesaler()->create();

        return SupplierSyncRun::factory()->for($supplier, 'supplier')->create(['active_supplier_id' => $supplier->id, 'configuration_snapshot' => ['version' => 1, 'selected_source' => 'icorp']]);
    }
}
