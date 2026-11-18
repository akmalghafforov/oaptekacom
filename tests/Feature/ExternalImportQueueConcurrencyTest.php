<?php

namespace Tests\Feature;

use App\Models\ExternalImportQueueItem;
use App\Models\Organization;
use App\Models\SupplierSyncRun;
use App\Models\User;
use App\Services\ExternalImportQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ExternalImportQueueConcurrencyTest extends TestCase
{
    public function test_contended_cache_lock_does_not_abort_supplier_transaction(): void
    {
        if (getenv('EXTERNAL_QUEUE_CONCURRENCY_TESTS') !== '1' || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires an isolated PostgreSQL test database.');
        }
        $this->assertMatchesRegularExpression('/(?:^|[_-])test(?:ing)?(?:[_-]|$)/i', DB::connection()->getDatabaseName());
        config(['cache.stores.database.lock_connection' => 'pgsql_locks']);
        $supplier = Organization::factory()->wholesaler()->create();
        $name = 'external-queue-test:'.Str::uuid();
        $owner = Cache::store('database')->lock($name, 60);
        $this->assertTrue($owner->get());

        try {
            DB::transaction(function () use ($supplier, $name): void {
                Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                $this->assertFalse(Cache::store('database')->lock($name, 60)->get());
                $supplier->update(['name' => 'Committed after lock contention']);
            });
        } finally {
            $owner->release();
        }

        $this->assertSame('Committed after lock contention', $supplier->fresh()->name);
    }

    #[TestWith(['same_lane'])]
    #[TestWith(['duplicate'])]
    #[TestWith(['different_lanes'])]
    #[TestWith(['killed_worker'])]
    #[TestWith(['concurrent_enqueue'])]
    public function test_concurrent_workers_obey_durable_lane_ownership(string $mode): void
    {
        if (getenv('EXTERNAL_QUEUE_CONCURRENCY_TESTS') !== '1' || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires a migrated isolated PostgreSQL test database and EXTERNAL_QUEUE_CONCURRENCY_TESTS=1.');
        }
        $this->assertMatchesRegularExpression('/(?:^|[_-])test(?:ing)?(?:[_-]|$)/i', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        $users = User::factory()->count(2)->create();
        $suppliers = Organization::factory()->wholesaler()->count(2)->create();
        $runs = $suppliers->map(fn ($supplier) => SupplierSyncRun::factory()->for($supplier, 'supplier')->create(['active_supplier_id' => $supplier->id]));
        $queue = app(ExternalImportQueue::class);
        foreach ($runs as $index => $run) {
            if ($mode !== 'concurrent_enqueue') {
                $queue->enqueue($run, $users[$mode === 'different_lanes' ? $index : 0]->id);
            }
        }
        if ($mode === 'concurrent_enqueue') {
            DB::table('external_import_queue_lanes')->insert(['id' => 'user:'.$users[0]->id]);
        }
        $directory = sys_get_temp_dir().'/external-queue-'.Str::uuid();
        mkdir($directory, 0700);
        $workers = [];
        try {
            foreach ($mode === 'killed_worker' ? [0] : [0, 1] as $index) {
                $runId = $runs[$mode === 'duplicate' ? 0 : $index]->id;
                $code = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['queue.default' => 'null']);
$run = App\Models\SupplierSyncRun::findOrFail((int) $argv[1]);
$directory = $argv[2];
$index = $argv[3];
$mode = $argv[4];
touch($directory.'/ready-'.$index);
$deadline = microtime(true) + 10;
while (! is_file($directory.'/go')) {
    if (microtime(true) > $deadline) { exit(2); }
    usleep(10000);
}
if ($mode === 'concurrent_enqueue') {
    if ($index === '0') {
        Illuminate\Support\Facades\DB::transaction(function () use ($run, $directory): void {
            app(App\Services\ExternalImportQueue::class)->enqueue($run, (int) $GLOBALS['argv'][5]);
            touch($directory.'/uncommitted');
            usleep(300000);
        });
    } else {
        $deadline = microtime(true) + 5;
        while (! is_file($directory.'/uncommitted')) {
            if (microtime(true) > $deadline) { exit(3); }
            usleep(10000);
        }
        app(App\Services\ExternalImportQueue::class)->enqueue($run, (int) $argv[5]);
    }
}
$executed = false;
app(App\Services\ExternalImportQueue::class)->execute($run, 1, function () use ($run, $directory, $index, $mode, &$executed): void {
    $executed = true;
    touch($directory.'/entered-'.$index);
    if ($mode === 'killed_worker') {
        $run->update(['reason' => 'uncommitted']);
        sleep(10);
    }
    if ($mode === 'different_lanes') {
        $deadline = microtime(true) + 5;
        while (count(glob($directory.'/entered-*')) !== 2) {
            if (microtime(true) > $deadline) { throw new RuntimeException('Independent lanes could not progress concurrently.'); }
            usleep(10000);
        }
    }
    $run->update(['reason' => 'executed']);
    if ($mode === 'duplicate') {
        $run->update(['status' => 'completed', 'active_supplier_id' => null, 'finished_at' => now()]);
    }
});
echo $executed ? 'executed' : 'waiting';
CODE;
                $worker = new Process([PHP_BINARY, '-r', $code, (string) $runId, $directory, (string) $index, $mode, (string) $users[0]->id], base_path(), null, null, 20);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($directory.'/ready-*')) !== count($workers) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(count($workers), glob($directory.'/ready-*'));
            touch($directory.'/go');
            if ($mode === 'killed_worker') {
                $deadline = microtime(true) + 5;
                while (! is_file($directory.'/entered-0') && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertFileExists($directory.'/entered-0');
                $workers[0]->stop(0, 9);
                $item = ExternalImportQueueItem::findOrFail($runs[0]->fresh()->execution_queue_item_id);
                $this->assertNotNull($item->worker_token);
                $this->assertNull($runs[0]->fresh()->reason);
                $item->update(['worker_expires_at' => now()->subSecond()]);
                $queue->execute($runs[0], 1, fn () => $runs[0]->update(['reason' => 'recovered']));
                $this->assertSame('recovered', $runs[0]->fresh()->reason);
                $this->assertNull($item->fresh()->worker_token);

                return;
            }
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }
            $outputs = array_map(fn (Process $worker): string => $worker->getOutput(), $workers);
            sort($outputs);
            $this->assertSame($mode === 'different_lanes' ? ['executed', 'executed'] : ['executed', 'waiting'], $outputs);
            if ($mode === 'concurrent_enqueue') {
                $this->assertSame('executed', $runs[0]->fresh()->reason);
            }
            $this->assertSame($mode === 'different_lanes' ? 2 : 1, SupplierSyncRun::whereIn('id', $runs->modelKeys())->where('reason', 'executed')->count());
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            $lanes = ExternalImportQueueItem::whereIn('supplier_sync_run_id', $runs->modelKeys())->pluck('lane_id')->all();
            ExternalImportQueueItem::whereIn('supplier_sync_run_id', $runs->modelKeys())->delete();
            DB::table('external_import_queue_lanes')->whereIn('id', $lanes)->delete();
            SupplierSyncRun::whereIn('id', $runs->modelKeys())->delete();
            Organization::whereIn('id', $suppliers->modelKeys())->delete();
            User::whereIn('id', $users->modelKeys())->delete();
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
