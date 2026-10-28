<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SupplierReview;
use App\Models\User;
use App\Services\OrganizationApprovalService;
use App\Services\TelegramSupplierReviews;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TelegramSupplierReviewConcurrencyTest extends TestCase
{
    #[TestWith(['approve', 'reject'])]
    #[TestWith(['reject', 'approve'])]
    #[TestWith(['web', 'reject'])]
    public function test_competing_transactions_make_only_one_decision(string $first, string $second): void
    {
        if (! getenv('TELEGRAM_POSTGRES_LOCK_TESTS') || DB::getDriverName() !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'telegram_reviews_test' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires a disposable PostgreSQL telegram_reviews_test database and TELEGRAM_POSTGRES_LOCK_TESTS=1.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config()->set('telegram.enabled', false);
        $admin = User::factory()->admin()->create();
        config()->set('telegram.admin_mapping', ['12345' => $admin->id]);
        config()->set('telegram.group_chat_id', '-100123');
        $supplier = Organization::factory()->wholesaler()->pending()->create();
        User::factory()->wholesaler($supplier)->create(['phone_verified_at' => now()]);
        $review = SupplierReview::create(['organization_id' => $supplier->id, 'token' => bin2hex(random_bytes(16)), 'chat_id' => '-100123', 'message_id' => '42', 'delivery_state' => 'sent']);
        $callback = fn (string $action): array => ['from' => ['id' => 12345], 'data' => 'supplier:'.$action.':'.$review->token,
            'message' => ['message_id' => 42, 'chat' => ['id' => -100123]]];
        $marker = tempnam(sys_get_temp_dir(), 'telegram-lock-');
        unlink($marker);
        DB::purge();
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                $deadline = microtime(true) + 10;
                while (! file_exists($marker) && microtime(true) < $deadline) {
                    usleep(10000);
                }
                if (! file_exists($marker)) {
                    exit(2);
                }
                file_put_contents($marker.'.started', 'started');
                app(TelegramSupplierReviews::class)->decide($callback($second));
                file_put_contents($marker.'.done', 'done');
                DB::disconnect();
                exit(0);
            } catch (\Throwable $exception) {
                file_put_contents($marker.'.error', $exception->getMessage());
                exit(1);
            }
        }
        try {
            DB::transaction(function () use ($supplier, $admin, $first, $callback, $marker): void {
                Organization::whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                file_put_contents($marker, 'locked');
                $deadline = microtime(true) + 10;
                while (! file_exists($marker.'.started') && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertFileExists($marker.'.started');
                usleep(300000);
                $this->assertFileDoesNotExist($marker.'.done', 'The competing decision must wait for the organization lock.');
                if ($first === 'web') {
                    app(OrganizationApprovalService::class)->approve($supplier, $admin);
                } else {
                    app(TelegramSupplierReviews::class)->decide($callback($first));
                }
            });
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status), file_exists($marker.'.error') ? file_get_contents($marker.'.error') : 'Competing process failed.');
            $this->assertSame($first === 'reject' ? 'blocked' : 'active', $supplier->fresh()->status);
            $this->assertDatabaseCount('audit_events', 1);
            $this->assertSame($first === 'reject' ? 'rejected' : 'approved', $review->fresh()->decision);
        } finally {
            foreach (['', '.started', '.done', '.error'] as $suffix) {
                if (file_exists($marker.$suffix)) {
                    unlink($marker.$suffix);
                }
            }
        }
    }
}
